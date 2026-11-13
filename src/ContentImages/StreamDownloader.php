<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

/**
 * Downloads through PHP streams, so the library needs no HTTP client.
 *
 * The URL comes from HTML an editor typed in, so it must not reach the internal network (SSRF): the host is resolved
 * first, a private, loopback, link-local or reserved address is refused, and the request goes to the very address that
 * was checked - resolving it again could return another one. Redirects are followed by hand, each hop checked again.
 */
final class StreamDownloader implements Downloader
{
	private const int MAX_REDIRECTS = 3;

	/**
	 * @param bool $allowPrivateNetworks only for a local environment downloading from its own network
	 */
	public function __construct(
		private readonly float $timeout = 20,
		private readonly bool $allowPrivateNetworks = false,
	) {
	}

	public function download(string $url, int $maxSize): ?string
	{
		for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
			$parts = parse_url($url);
			$scheme = strtolower($parts['scheme'] ?? '');
			$host = $parts['host'] ?? null;

			if (!in_array($scheme, ['http', 'https'], true) || $host === null || isset($parts['user']) || isset($parts['pass'])) {
				return null;
			}

			$ip = $this->resolve($host);
			if ($ip === null) {
				return null;
			}

			$port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
			$target = $scheme . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port
				. ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

			$context = stream_context_create([
				'http' => [
					'timeout' => $this->timeout,
					'follow_location' => 0,
					'ignore_errors' => true,
					'header' => 'Host: ' . $host . (isset($parts['port']) ? ':' . $parts['port'] : ''),
					'user_agent' => 'Mozilla/5.0 (compatible; adt-files-content-image)',
				],
				// the certificate is checked against the host, not the address the request goes to
				'ssl' => [
					'peer_name' => $host,
					'SNI_enabled' => true,
				],
			]);

			$handle = @fopen($target, 'rb', false, $context);
			if ($handle === false) {
				return null;
			}

			try {
				$headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
				$status = $this->getStatus($headers);

				if ($status >= 300 && $status < 400) {
					$location = $this->getHeader($headers, 'location');
					if ($location === null) {
						return null;
					}
					$url = $this->absolutize($location, $scheme, $host, $parts['port'] ?? null, $parts['path'] ?? '/');
					continue;
				}

				if ($status !== 200) {
					return null;
				}

				$content = stream_get_contents($handle, $maxSize + 1);
			} finally {
				fclose($handle);
			}

			return $content === false || strlen($content) > $maxSize ? null : $content;
		}

		return null;
	}

	/**
	 * The address the request will go to, null when the host does not resolve or every address is off limits.
	 */
	private function resolve(string $host): ?string
	{
		$host = trim($host, '[]');

		if (filter_var($host, FILTER_VALIDATE_IP)) {
			$addresses = [$host];
		} else {
			$records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
			$addresses = array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records));

			if (!$addresses) {
				$addresses = array_filter([gethostbyname($host)], fn (string $ip) => $ip !== $host);
			}
		}

		if (!$addresses) {
			return null;
		}

		// one forbidden address is enough to refuse - the host is not one of ours to trust
		foreach ($addresses as $address) {
			if (!$this->isAllowed($address)) {
				return null;
			}
		}

		return reset($addresses);
	}

	private function isAllowed(string $ip): bool
	{
		if ($this->allowPrivateNetworks) {
			return true;
		}

		// an IPv4 address mapped into IPv6 (::ffff:127.0.0.1) is checked as the IPv4 one it is
		if (preg_match('~^::ffff:(\d+\.\d+\.\d+\.\d+)$~i', $ip, $match)) {
			$ip = $match[1];
		}

		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			return false;
		}

		// shared address space (100.64.0.0/10) is not in PHP's ranges, some clouds keep their metadata service there
		$long = ip2long($ip);

		return $long === false || ($long & 0xFFC00000) !== (ip2long('100.64.0.0') & 0xFFC00000);
	}

	/**
	 * @param string[] $headers
	 */
	private function getStatus(array $headers): int
	{
		$status = 0;
		foreach ($headers as $header) {
			if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) {
				$status = (int) $match[1];
			}
		}

		return $status;
	}

	/**
	 * @param string[] $headers
	 */
	private function getHeader(array $headers, string $name): ?string
	{
		foreach ($headers as $header) {
			if (stripos($header, $name . ':') === 0) {
				return trim(substr($header, strlen($name) + 1));
			}
		}

		return null;
	}

	private function absolutize(string $location, string $scheme, string $host, ?int $port, string $path): string
	{
		if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location)) {
			return $location;
		}

		$origin = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');

		if (str_starts_with($location, '//')) {
			return $scheme . ':' . $location;
		}

		if (str_starts_with($location, '/')) {
			return $origin . $location;
		}

		return $origin . rtrim(dirname($path), '/') . '/' . $location;
	}
}
