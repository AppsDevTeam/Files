<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

/**
 * Downloads through PHP streams, so the library needs no HTTP client.
 */
final class StreamDownloader implements Downloader
{
	public function __construct(
		private readonly float $timeout = 20,
	) {
	}

	public function download(string $url, int $maxSize): ?string
	{
		if (!preg_match('~^https?://~i', $url)) {
			return null;
		}

		$context = stream_context_create(['http' => [
			'timeout' => $this->timeout,
			'follow_location' => 1,
			'max_redirects' => 3,
			'ignore_errors' => false,
			'user_agent' => 'Mozilla/5.0 (compatible; adt-files-content-image)',
		]]);

		$handle = @fopen($url, 'rb', false, $context);
		if ($handle === false) {
			return null;
		}

		try {
			$content = stream_get_contents($handle, $maxSize + 1);
		} finally {
			fclose($handle);
		}

		return $content === false || strlen($content) > $maxSize ? null : $content;
	}
}
