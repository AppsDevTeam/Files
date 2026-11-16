<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Storage;

use ADT\Files\Storage\S3Storage;
use Exception;
use PHPUnit\Framework\Attributes\Test;

final class S3StorageTest extends S3TestCase
{
	#[Test]
	public function putStoresTheFileUnderItsNameAndContentType(): void
	{
		$storage = $this->dataStorage();
		$local = $this->localFile('hello');

		$storage->put('001/002_abcde_hello.txt', $local, 'text/plain');

		self::assertFileExists($local, 'put() copies, the source is for the caller to remove.');
		self::assertSame(5, $storage->getSize('001/002_abcde_hello.txt'));

		$object = self::client()->getObject(['Bucket' => getenv('ADT_FILES_S3_BUCKET'), 'Key' => $this->prefix . '/001/002_abcde_hello.txt']);
		self::assertSame('hello', (string) $object['Body']);
		self::assertSame('text/plain', $object['ContentType']);
	}

	#[Test]
	public function readStreamsTheContents(): void
	{
		$storage = $this->dataStorage();
		$contents = random_bytes(3 * 1024 * 1024);
		$storage->put('big.bin', $this->localFile($contents));

		$stream = $storage->read('big.bin');
		self::assertIsResource($stream);
		self::assertSame($contents, stream_get_contents($stream));
		fclose($stream);
	}

	#[Test]
	public function bigFileGoesInPartsAndIsCheckedBySize(): void
	{
		$storage = $this->dataStorage();
		$contents = random_bytes(12 * 1024 * 1024);
		$threshold = S3Storage::$multipartThreshold;
		S3Storage::$multipartThreshold = 5 * 1024 * 1024;

		try {
			$storage->put('parts.bin', $this->localFile($contents), 'application/octet-stream');
		} finally {
			S3Storage::$multipartThreshold = $threshold;
		}

		self::assertSame(12 * 1024 * 1024, $storage->getSize('parts.bin'));
		$object = self::client()->getObject(['Bucket' => getenv('ADT_FILES_S3_BUCKET'), 'Key' => $this->prefix . '/parts.bin']);
		self::assertSame($contents, (string) $object['Body']);
		self::assertSame('application/octet-stream', $object['ContentType']);
		self::assertStringContainsString('-', trim($object['ETag'], '"'), 'A multipart ETag is not a plain md5.');
	}

	#[Test]
	public function missingFileHasNoSizeAndCannotBeRead(): void
	{
		$storage = $this->dataStorage();

		self::assertNull($storage->getSize('nope.txt'));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot open');
		$storage->read('nope.txt');
	}

	#[Test]
	public function deleteRemovesTheFileAndToleratesAMissingOne(): void
	{
		$storage = $this->dataStorage();
		$storage->put('a.txt', $this->localFile('a'));

		$storage->delete('a.txt');
		self::assertNull($storage->getSize('a.txt'));

		$storage->delete('a.txt');
		self::assertNull($storage->getSize('a.txt'));
	}

	#[Test]
	public function pathIsReadableByPhpFileFunctions(): void
	{
		$storage = $this->dataStorage();
		$storage->put('dir/file.txt', $this->localFile('through the wrapper'));

		$path = $storage->getPath('dir/file.txt');
		self::assertSame('s3://' . getenv('ADT_FILES_S3_BUCKET') . '/' . $this->prefix . '/dir/file.txt', $path);
		self::assertSame('through the wrapper', file_get_contents($path));

		$handle = fopen($path, 'rb');
		self::assertSame('through', fread($handle, 7));
		fclose($handle);
	}

	#[Test]
	public function urlIsBuiltFromTheConfiguredBase(): void
	{
		self::assertNull($this->dataStorage()->getUrl('a/b.txt'));
		self::assertSame('https://files.example.com/a/b.txt', $this->dataStorage('https://files.example.com/')->getUrl('a/b.txt'));
	}

	#[Test]
	public function listReturnsNamesRelativeToThePrefixWithSizeAndTime(): void
	{
		$storage = $this->dataStorage();
		$other = $this->dataStorage(null, 'other');
		$storage->put('001/a.txt', $this->localFile('aaa'));
		$storage->put('002/b.txt', $this->localFile('bb'));
		$other->put('001/c.txt', $this->localFile('c'));

		$listed = [];
		foreach ($storage->list() as $name => [$size, $modified]) {
			$listed[$name] = $size;
			self::assertEqualsWithDelta(time(), $modified, 120);
		}

		// the "other" storage lives under a longer prefix inside this one, so its file is seen
		// here with its full relative name - a storage never shares a prefix with another one
		self::assertSame(['001/a.txt' => 3, '002/b.txt' => 2, 'other/001/c.txt' => 1], $listed);
		self::assertSame(['001/c.txt'], array_keys(iterator_to_array($other->list())));
	}

	#[Test]
	public function unreadableSourceIsRefused(): void
	{
		$this->expectExceptionMessage('Cannot read');
		$this->dataStorage()->put('x.txt', '/nonexistent/file');
	}

	#[Test]
	public function createBuildsAWorkingClient(): void
	{
		$storage = S3Storage::create(
			getenv('ADT_FILES_S3_ENDPOINT'),
			getenv('ADT_FILES_S3_KEY'),
			getenv('ADT_FILES_S3_SECRET'),
			getenv('ADT_FILES_S3_BUCKET'),
			getenv('ADT_FILES_S3_REGION') ?: 'garage',
			$this->prefix . '/created',
		);
		$storage->put('a.txt', $this->localFile('created'));

		try {
			self::assertSame('created', file_get_contents($storage->getPath('a.txt')));
		} finally {
			$storage->delete('a.txt');
		}

		// the same server and key again share the client, so the wrapper is not contested
		$archive = S3Storage::create(
			getenv('ADT_FILES_S3_ENDPOINT'),
			getenv('ADT_FILES_S3_KEY'),
			getenv('ADT_FILES_S3_SECRET'),
			getenv('ADT_FILES_S3_ARCHIVE_BUCKET'),
			getenv('ADT_FILES_S3_REGION') ?: 'garage',
		);
		self::assertSame($storage->getClient(), $archive->getClient());
	}

	#[Test]
	public function anotherServerNeedsItsOwnProtocol(): void
	{
		$this->expectExceptionMessage('already belongs to another S3 client');
		S3Storage::create('http://127.0.0.1:1', 'other', 'other', 'bucket');
	}

	#[Test]
	public function anotherServerWorksUnderItsOwnProtocol(): void
	{
		$storage = S3Storage::create('http://127.0.0.1:1', 'other', 'other', 'bucket', protocol: 's3-other');

		self::assertSame('s3-other://bucket/a.txt', $storage->getPath('a.txt'));
	}
}
