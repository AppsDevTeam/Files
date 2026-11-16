<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Storage;

use ADT\Files\Storage\LocalStorage;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalStorageTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/adt-files-local-test-' . bin2hex(random_bytes(8));
		mkdir($this->dir, 0777, true);
	}

	protected function tearDown(): void
	{
		exec('chmod -R u+w ' . escapeshellarg($this->dir) . '; rm -rf ' . escapeshellarg($this->dir));
	}

	#[Test]
	public function putCopiesVerifiedAndLeavesNoTemporaryFile(): void
	{
		$storage = new LocalStorage($this->dir . '/data', 'https://example.com/data/');
		$source = $this->dir . '/source.txt';
		file_put_contents($source, 'contents');

		$storage->put('001/a.txt', $source, 'text/plain', md5('contents'));

		self::assertFileExists($source);
		self::assertSame('contents', file_get_contents($storage->getPath('001/a.txt')));
		self::assertSame(8, $storage->getSize('001/a.txt'));
		self::assertSame('https://example.com/data/001/a.txt', $storage->getUrl('001/a.txt'));
		self::assertSame(['001/a.txt' => 8], array_map(fn ($i) => $i[0], iterator_to_array($storage->list())));
	}

	#[Test]
	public function wrongKnownHashIsRefused(): void
	{
		$storage = new LocalStorage($this->dir . '/data');
		$source = $this->dir . '/source.txt';
		file_put_contents($source, 'contents');

		try {
			$storage->put('a.txt', $source, null, md5('something else'));
			self::fail('A copy that does not match the hash the caller knows must not be accepted.');
		} catch (Exception $e) {
			self::assertStringContainsString('does not match', $e->getMessage());
		}

		self::assertNull($storage->getSize('a.txt'));
		self::assertSame([], iterator_to_array($storage->list()), 'The temporary copy must be cleaned up.');
	}

	#[Test]
	public function deleteThrowsWhenTheFileStays(): void
	{
		$storage = new LocalStorage($this->dir . '/data');
		$source = $this->dir . '/source.txt';
		file_put_contents($source, 'contents');
		$storage->put('locked/a.txt', $source);
		chmod($this->dir . '/data/locked', 0555);

		try {
			$this->expectExceptionMessage('Cannot delete');
			$storage->delete('locked/a.txt');
		} finally {
			chmod($this->dir . '/data/locked', 0755);
		}
	}

	#[Test]
	public function deletingAMissingFileIsFine(): void
	{
		$storage = new LocalStorage($this->dir . '/data');
		$storage->delete('nope.txt');
		self::assertNull($storage->getSize('nope.txt'));
	}
}
