<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Storage;

use ADT\Files\Storage\S3Storage;
use Aws\S3\S3ClientInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests against a real S3 server - tests/s3/garage.sh starts one and prints the variables.
 * Each test gets its own prefix in the buckets, so runs do not see each other's objects.
 */
abstract class S3TestCase extends TestCase
{
	private static ?S3ClientInterface $client = null;
	protected string $prefix;
	/** @var S3Storage[] */
	private array $storages = [];

	protected function setUp(): void
	{
		if (!getenv('ADT_FILES_S3_ENDPOINT')) {
			self::markTestSkipped('ADT_FILES_S3_ENDPOINT is not set, see tests/s3/garage.sh.');
		}

		$this->prefix = 'test-' . bin2hex(random_bytes(4));
	}

	protected function tearDown(): void
	{
		foreach ($this->storages as $storage) {
			foreach ($storage->list() as $name => $_) {
				$storage->delete($name);
			}
		}
	}

	/**
	 * The client create() builds and caches - the wrapper protocol has one client, so the tests
	 * of create() and those with a client of their own have to agree on it.
	 */
	protected static function client(): S3ClientInterface
	{
		return self::$client ??= S3Storage::create(
			getenv('ADT_FILES_S3_ENDPOINT'),
			getenv('ADT_FILES_S3_KEY'),
			getenv('ADT_FILES_S3_SECRET'),
			getenv('ADT_FILES_S3_BUCKET'),
			getenv('ADT_FILES_S3_REGION') ?: 'garage',
		)->getClient();
	}

	protected function dataStorage(?string $url = null, ?string $subPrefix = null): S3Storage
	{
		return $this->storages[] = new S3Storage(self::client(), getenv('ADT_FILES_S3_BUCKET'), $this->prefix . ($subPrefix ? '/' . $subPrefix : ''), $url);
	}

	protected function archiveStorage(?string $subPrefix = null): S3Storage
	{
		return $this->storages[] = new S3Storage(self::client(), getenv('ADT_FILES_S3_ARCHIVE_BUCKET'), $this->prefix . ($subPrefix ? '/' . $subPrefix : ''));
	}

	protected function localFile(string $contents): string
	{
		$path = tempnam(sys_get_temp_dir(), 'adt-files-s3-test-');
		file_put_contents($path, $contents);

		return $path;
	}
}
