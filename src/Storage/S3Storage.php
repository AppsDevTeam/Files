<?php

declare(strict_types=1);

namespace ADT\Files\Storage;

use Aws\Exception\MultipartUploadException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Aws\S3\MultipartUploader;
use Aws\S3\StreamWrapper;
use Exception;
use GuzzleHttp\Psr7\StreamWrapper as Psr7StreamWrapper;
use Throwable;

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
	/**
	 * Od jake velikosti jde upload po castech (S3 ma pro jeden PUT limit 5 GB). Po castech
	 * neni Content-MD5 celku, overuje se jen velikost; cast ma min. 5 MB.
	 */
	public static int $multipartThreshold = 1024 * 1024 * 1024;

	/** Velikost casti multipartu - s 5 MB (minimum S3) by 1 GB znamenal pres 200 pozadavku. */
	public static int $multipartPartSize = 64 * 1024 * 1024;

	/** @var array<string, S3ClientInterface> protokol wrapperu => klient, ktery ho obsluhuje */
	private static array $registered = [];

	/** @var array<string, S3Client> klienti z create() podle serveru a klice - dve uloziste se stejnymi udaji sdili klienta (a wrapper) */
	private static array $clients = [];

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

		// jeden protokol = jeden klient (create() se stejnymi udaji vraci tehoz)
		if (isset(self::$registered[$protocol]) && self::$registered[$protocol] !== $client) {
			throw new Exception("Stream wrapper '{$protocol}://' already belongs to another S3 client, give this storage its own \$protocol.");
		}
		if (!isset(self::$registered[$protocol])) {
			StreamWrapper::register($client, $protocol);
			self::$registered[$protocol] = $client;
		}
	}

	/**
	 * Uloziste bez rucniho sestavovani S3Client - pro konfiguraci v neonu. Path style
	 * adresovani (endpoint/bucket/key), ktere Garage i MinIO bez DNS wildcardu potrebuji.
	 */
	public static function create(string $endpoint, string $key, string $secret, string $bucket, string $region = 'garage', ?string $prefix = null, ?string $url = null, string $protocol = 's3'): static
	{
		$client = self::$clients[$endpoint . ' ' . $region . ' ' . $key] ??= new S3Client([
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

	public function put(string $name, string $localPath, ?string $contentType = null, ?string $md5 = null): void
	{
		clearstatcache(true, $localPath);
		$size = @filesize($localPath);
		$md5 ??= @md5_file($localPath);

		if ($size === false || $md5 === false) {
			throw new Exception('Cannot read ' . $localPath . '.');
		}

		try {
			if ($size >= self::$multipartThreshold) {
				new MultipartUploader($this->client, $localPath, $this->multipartConfig($name, $contentType))->upload();
				$etag = '';
			} else {
				$result = $this->client->putObject(array_filter([
					'Bucket' => $this->bucket,
					'Key' => $this->key($name),
					'SourceFile' => $localPath,
					'ContentMD5' => base64_encode(hex2bin($md5)),
					'ContentType' => $contentType,
				]));
				$etag = trim((string) ($result['ETag'] ?? ''), '"');
			}
		} catch (S3Exception $e) {
			throw new Exception('Uploading ' . $this->getPath($name) . ' failed: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()), 0, $e);
		} catch (MultipartUploadException $e) {
			// nahrane casti by jinak zustaly v bucketu jako nedokonceny upload - Garage je bez
			// lifecycle pravidla drzi donekonecna
			if ($id = $e->getState()->getId()) {
				try {
					$this->client->abortMultipartUpload($id);
				} catch (Throwable) {
				}
			}
			throw new Exception('Uploading ' . $this->getPath($name) . ' failed: ' . $e->getMessage(), 0, $e);
		}

		// server telo overil proti Content-MD5; ETag jednodilneho uploadu je md5 obsahu,
		// takze sedi i to, co server ulozil
		if ($etag !== '' && !str_contains($etag, '-') && $etag !== $md5) {
			$this->delete($name);
			throw new Exception('Hash of the uploaded ' . $this->getPath($name) . ' does not match the original.');
		}

		if ($this->getSize($name) !== $size) {
			$this->delete($name);
			throw new Exception('Size of the uploaded ' . $this->getPath($name) . ' does not match the original.');
		}
	}

	/**
	 * @return array<string, mixed> konfigurace MultipartUploaderu
	 */
	protected function multipartConfig(string $name, ?string $contentType): array
	{
		return [
			'bucket' => $this->bucket,
			'key' => $this->key($name),
			'part_size' => self::$multipartPartSize,
			'before_initiate' => function ($command) use ($contentType): void {
				if ($contentType !== null) {
					$command['ContentType'] = $contentType;
				}
			},
		];
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
