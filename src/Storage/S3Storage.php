<?php

declare(strict_types=1);

namespace ADT\Files\Storage;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Aws\S3\StreamWrapper;
use Exception;
use GuzzleHttp\Psr7\StreamWrapper as Psr7StreamWrapper;

/**
 * Bucket v S3 (Garage, MinIO, AWS) - datove uloziste aplikace i archiv. Vyzaduje
 * aws/aws-sdk-php (v suggest).
 *
 * put() posila Content-MD5, takze server telo overi a nedopsany nebo poskozeny prenos
 * odmitne; po nahrani se jeste overi velikost. Uloziste sdili jedno pripojeni (S3Client), ale
 * kazde ma vlastni bucket a prefix - datovy a archivni bucket na stejnem serveru jsou dve
 * instance se stejnym klientem.
 *
 * getPath() vraci URL stream wrapperu SDK ("s3://bucket/key"), takze fopen(), file_get_contents(),
 * copy() nebo Nette FileResponse nad getPath() souboru fungujou stejne jako u souboru na disku.
 * Wrapper je per protokol a protokol ma jednoho klienta: dve uloziste na ruznych serverech
 * (data v jedne Garage, archiv v jine) musi mit kazde svuj $protocol ('s3', 's3-archive').
 * Externi programy (exec) s touhle cestou nic neudelaji - tem se soubor nejdriv stahne.
 */
class S3Storage implements Storage
{
	/** @var array<string, string> protokol wrapperu => server a klic klienta, ktery ho obsluhuje */
	private static array $registered = [];

	protected string $prefix;

	/**
	 * @param string|null $prefix adresar v bucketu, pod kterym soubory lezi (bez lomitka na koncich), null = koren bucketu
	 * @param string|null $url verejna URL, pod kterou jsou objekty dostupne (nginx pred bucketem, website endpoint); null = soubory nemaji URL
	 * @param string $protocol protokol stream wrapperu pro getPath()
	 */
	public function __construct(
		protected S3ClientInterface $client,
		protected string $bucket,
		?string $prefix = null,
		protected ?string $url = null,
		protected string $protocol = 's3',
	) {
		$this->prefix = trim((string) $prefix, '/');
		$this->url = $url !== null ? rtrim($url, '/') : null;

		// tentyz server pod jednim protokolem - stejny klient, nebo jiny se stejnym serverem
		// a klicem (dve uloziste z create() se stejnymi udaji)
		if (isset(self::$registered[$protocol]) && self::$registered[$protocol] !== self::identity($client)) {
			throw new Exception("Stream wrapper '{$protocol}://' already belongs to another S3 server, give this storage its own \$protocol.");
		}
		if (!isset(self::$registered[$protocol])) {
			StreamWrapper::register($client, $protocol);
			self::$registered[$protocol] = self::identity($client);
		}
	}

	private static function identity(S3ClientInterface $client): string
	{
		return $client->getEndpoint() . ' ' . $client->getRegion() . ' ' . $client->getCredentials()->wait()->getAccessKeyId();
	}

	/**
	 * Uloziste bez rucniho sestavovani S3Client - pro konfiguraci v neonu. Path style
	 * adresovani (endpoint/bucket/key), ktere Garage i MinIO bez DNS wildcardu potrebuji.
	 */
	public static function create(string $endpoint, string $key, string $secret, string $bucket, string $region = 'garage', ?string $prefix = null, ?string $url = null, string $protocol = 's3'): static
	{
		$client = new S3Client([
			'version' => 'latest',
			'region' => $region,
			'endpoint' => $endpoint,
			'use_path_style_endpoint' => true,
			'credentials' => ['key' => $key, 'secret' => $secret],
		]);

		return new static($client, $bucket, $prefix, $url, $protocol);
	}

	public function getClient(): S3ClientInterface
	{
		return $this->client;
	}

	public function getBucket(): string
	{
		return $this->bucket;
	}

	public function put(string $name, string $localPath, ?string $contentType = null): void
	{
		clearstatcache(true, $localPath);
		$size = @filesize($localPath);
		$md5 = @md5_file($localPath, true);

		if ($size === false || $md5 === false) {
			throw new Exception('Cannot read ' . $localPath . '.');
		}

		try {
			$result = $this->client->putObject(array_filter([
				'Bucket' => $this->bucket,
				'Key' => $this->key($name),
				'SourceFile' => $localPath,
				'ContentMD5' => base64_encode($md5),
				'ContentType' => $contentType,
			]));
		} catch (S3Exception $e) {
			throw new Exception('Uploading ' . $this->getPath($name) . ' failed: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()), 0, $e);
		}

		// server telo overil proti Content-MD5; ETag jednodilneho uploadu je md5 obsahu,
		// takze sedi i to, co server ulozil
		$etag = trim((string) ($result['ETag'] ?? ''), '"');
		if ($etag !== '' && !str_contains($etag, '-') && $etag !== bin2hex($md5)) {
			$this->delete($name);
			throw new Exception('Hash of the uploaded ' . $this->getPath($name) . ' does not match the original.');
		}

		if ($this->getSize($name) !== $size) {
			$this->delete($name);
			throw new Exception('Size of the uploaded ' . $this->getPath($name) . ' does not match the original.');
		}
	}

	public function read(string $name)
	{
		try {
			$result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $this->key($name)]);
		} catch (S3Exception $e) {
			throw new Exception('Cannot open ' . $this->getPath($name) . ' for reading: ' . ($e->getAwsErrorCode() ?: $e->getMessage()), 0, $e);
		}

		return Psr7StreamWrapper::getResource($result['Body']);
	}

	public function getSize(string $name): ?int
	{
		try {
			$result = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $this->key($name)]);
		} catch (S3Exception $e) {
			if ($e->getStatusCode() === 404) {
				return null;
			}
			throw new Exception('Cannot stat ' . $this->getPath($name) . ': ' . ($e->getAwsErrorCode() ?: $e->getMessage()), 0, $e);
		}

		return (int) $result['ContentLength'];
	}

	public function delete(string $name): void
	{
		try {
			$this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $this->key($name)]);
		} catch (S3Exception $e) {
			throw new Exception('Cannot delete ' . $this->getPath($name) . ': ' . ($e->getAwsErrorCode() ?: $e->getMessage()), 0, $e);
		}
	}

	public function getPath(string $name): string
	{
		return $this->protocol . '://' . $this->bucket . '/' . $this->key($name);
	}

	public function getUrl(string $name): ?string
	{
		return $this->url !== null ? $this->url . '/' . $name : null;
	}

	public function list(): iterable
	{
		$args = ['Bucket' => $this->bucket];
		if ($this->prefix !== '') {
			$args['Prefix'] = $this->prefix . '/';
		}

		foreach ($this->client->getPaginator('ListObjectsV2', $args) as $page) {
			foreach ($page['Contents'] ?? [] as $object) {
				$name = $this->prefix !== '' ? substr($object['Key'], strlen($this->prefix) + 1) : $object['Key'];
				yield $name => [(int) $object['Size'], $object['LastModified']->getTimestamp()];
			}
		}
	}

	protected function key(string $name): string
	{
		return $this->prefix !== '' ? $this->prefix . '/' . $name : $name;
	}
}
