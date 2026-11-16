<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Storage;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\Files\Archiver;
use ADT\Files\Console\DeleteOrphanedFilesCommand;
use ADT\Files\Listeners\FileListener;
use ADT\Files\Storage\Storage;
use ADT\Files\Tests\Fixtures\Entity\TestFile;
use ADT\Files\Tests\Fixtures\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The library on top of S3: files saved into a bucket, archived into another one and in
 * every combination with a directory on the disk.
 */
final class S3FilesTest extends S3TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/adt-files-s3-test-' . bin2hex(random_bytes(8));
		mkdir($this->dir . '/data', 0777, true);
		mkdir($this->dir . '/archive', 0777, true);
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		if (isset($this->dir)) {
			exec('rm -rf ' . escapeshellarg($this->dir));
		}
	}

	#[Test]
	public function fileIsSavedIntoTheBucketWithItsMetadata(): void
	{
		$storage = $this->dataStorage('https://files.example.com');
		$em = EntityManagerFactory::create($storage);

		$file = $this->createFile($em, 'in the bucket');

		self::assertSame(13, $file->getSize());
		self::assertSame(md5('in the bucket'), $file->getHash());
		self::assertSame('text/plain', $file->getMimeType());
		self::assertSame(13, $storage->getSize($file->getFilename()));
		self::assertSame('in the bucket', $file->getContents());
		self::assertSame('in the bucket', file_get_contents($file->getPath()));
		self::assertSame('https://files.example.com/' . $file->getFilename(), $file->getUrl());

		$em->clear();
		self::assertSame('in the bucket', $em->find(TestFile::class, $file->getId())->getContents());
	}

	#[Test]
	public function temporaryFileIsConsumedStreamAndContentLeaveNothingBehind(): void
	{
		$em = EntityManagerFactory::create($this->dataStorage());

		$temporary = $this->localFile('upload');
		$em->persist($file = new TestFile()->setTemporaryFile($temporary, 'upload.txt'));
		$em->flush();
		self::assertFileDoesNotExist($temporary, 'The temporary file is moved into the storage, as it always was.');
		self::assertSame('upload', $file->getContents());

		$before = count(glob(sys_get_temp_dir() . '/adt-files-*') ?: []);
		$em->persist($streamed = new TestFile()->setStream('data://text/plain,streamed', 'streamed.txt'));
		$em->flush();
		self::assertSame('streamed', $streamed->getContents());
		self::assertCount($before, glob(sys_get_temp_dir() . '/adt-files-*') ?: [], 'No temporary file may be left behind.');
	}

	#[Test]
	public function deletingTheRowDeletesTheObject(): void
	{
		$storage = $this->dataStorage();
		$em = EntityManagerFactory::create($storage);
		$file = $this->createFile($em);
		$name = $file->getFilename();

		$em->remove($file);
		$em->flush();

		self::assertNull($storage->getSize($name));
	}

	#[Test]
	public function missingObjectIsAnErrorUnlessIgnored(): void
	{
		$storage = $this->dataStorage();
		$em = EntityManagerFactory::create($storage);
		$file = $this->createFile($em);
		$storage->delete($file->getFilename());

		try {
			$file->getContents();
			self::fail('Reading a missing object must throw.');
		} catch (Exception $e) {
			self::assertStringContainsString('Cannot open', $e->getMessage());
		}

		$file->ignoreMissingFile();
		self::assertSame('', $file->getContents());
	}

	#[Test]
	public function bucketToBucket(): void
	{
		$this->assertArchiving($this->dataStorage(), $this->archiveStorage());
	}

	#[Test]
	public function diskToBucket(): void
	{
		$this->assertArchiving($this->dir . '/data', $this->archiveStorage());
	}

	#[Test]
	public function bucketToDisk(): void
	{
		$this->assertArchiving($this->dataStorage(), $this->dir . '/archive');
	}

	#[Test]
	public function privateFilesHaveTheirOwnBucketPrefixes(): void
	{
		$public = $this->dataStorage(null, 'www');
		$private = $this->dataStorage(null, 'private');
		$publicArchive = $this->archiveStorage('www');
		$privateArchive = $this->archiveStorage('private');
		$em = EntityManagerFactory::create($public, archiveDir: $publicArchive, privateArchiveDir: $privateArchive, privateDataDir: $private);

		$em->persist($file = new TestFile()->setTemporaryContent('secret', 'secret.txt')->setIsPrivate(true));
		$em->flush();
		self::assertSame($private->getPath($file->getFilename()), $file->getPath());

		$this->archiver($em)->processArchive(TestFile::class, $file->getId());
		self::assertSame($privateArchive->getPath($file->getFilename()), $file->getPath());
		self::assertSame('secret', $file->getContents());
		self::assertNull($private->getSize($file->getFilename()));
	}

	#[Test]
	public function corruptedSourceIsNotArchived(): void
	{
		$data = $this->dataStorage();
		$archive = $this->archiveStorage();
		$em = EntityManagerFactory::create($data, archiveDir: $archive);
		$file = $this->createFile($em);

		$em->createQuery('UPDATE ' . TestFile::class . ' e SET e.hash = :hash WHERE e.id = :id')
			->execute(['hash' => md5('something else'), 'id' => $file->getId()]);
		$em->refresh($file);

		try {
			$this->archiver($em)->processArchive(TestFile::class, $file->getId());
			self::fail('A source that does not match the row must not be archived.');
		} catch (Exception $e) {
			self::assertStringContainsString('Hash', $e->getMessage());
		}

		self::assertNotNull($data->getSize($file->getFilename()));
		self::assertNull($archive->getSize($file->getFilename()));
		self::assertFalse($file->getIsArchived());
	}

	#[Test]
	public function leftoverOriginalIsRemovedOnRetryOnlyWhenTheArchiveCopyMatches(): void
	{
		$data = $this->dataStorage();
		$archive = $this->archiveStorage();
		$em = EntityManagerFactory::create($data, archiveDir: $archive);
		$file = $this->createFile($em, 'contents');
		$name = $file->getFilename();
		$this->archiver($em)->processArchive(TestFile::class, $file->getId());

		// the job died after the row was updated but before the original was deleted
		$data->put($name, $this->localFile('contents'));
		$this->archiver($em)->processArchive(TestFile::class, $file->getId());
		self::assertNull($data->getSize($name));

		// ...and an archive copy that does not match keeps the original
		$data->put($name, $this->localFile('contents'));
		$archive->put($name, $this->localFile('something longer'));
		try {
			$this->archiver($em)->processArchive(TestFile::class, $file->getId());
			self::fail('The original must stay when the archive copy does not match.');
		} catch (Exception $e) {
			self::assertStringContainsString('does not match', $e->getMessage());
		}
		self::assertNotNull($data->getSize($name));
	}

	#[Test]
	public function orphansInTheBucketAreListedAndDeleted(): void
	{
		$storage = $this->dataStorage();
		$em = EntityManagerFactory::create($storage);
		$kept = $this->createFile($em);
		$storage->put('001/orphan.txt', $this->localFile('orphan'));

		$tester = new CommandTester(new DeleteOrphanedFilesCommand($em, $storage));
		$tester->execute(['--min-age' => '0']);
		self::assertStringContainsString($storage->getPath('001/orphan.txt'), $tester->getDisplay());
		self::assertNotNull($storage->getSize('001/orphan.txt'), 'Nothing is deleted without --exec.');

		$tester->execute(['--min-age' => '0', '--exec' => true]);
		self::assertNull($storage->getSize('001/orphan.txt'));
		self::assertNotNull($storage->getSize($kept->getFilename()));
	}

	private function assertArchiving(string|Storage $data, string|Storage $archive): void
	{
		$em = EntityManagerFactory::create($data, archiveDir: $archive);
		$file = $this->createFile($em, 'archived contents');
		$id = $file->getId();
		$name = $file->getFilename();
		$listener = self::fileListener($em);
		$dataStorage = $listener->getDataStorageFor($file);
		$archiveStorage = $listener->getArchiveStorageFor($file);

		$this->archiver($em)->processArchive(TestFile::class, $id);

		self::assertNull($dataStorage->getSize($name), 'The original must be gone once the archive copy is in place.');
		self::assertSame(17, $archiveStorage->getSize($name));
		self::assertTrue($file->getIsArchived());
		self::assertSame($archiveStorage->getPath($name), $file->getPath());
		self::assertSame('archived contents', $file->getContents());

		$em->clear();
		$reloaded = $em->find(TestFile::class, $id);
		self::assertTrue($reloaded->getIsArchived());
		self::assertSame('archived contents', $reloaded->getContents());
		self::assertSame('archived contents', file_get_contents($reloaded->getPath()));

		$em->remove($reloaded);
		$em->flush();
		self::assertNull($archiveStorage->getSize($name), 'Deleting an archived row deletes the archive copy.');
	}

	private function archiver(EntityManagerInterface $em): Archiver
	{
		return new Archiver(self::fileListener($em), $em, $this->createStub(BackgroundQueue::class));
	}

	private static function fileListener(EntityManagerInterface $em): FileListener
	{
		foreach ($em->getEventManager()->getAllListeners() as $listeners) {
			foreach ($listeners as $listener) {
				if ($listener instanceof FileListener) {
					return $listener;
				}
			}
		}

		throw new Exception('FileListener is not registered.');
	}

	private function createFile(EntityManagerInterface $em, string $contents = 'contents'): TestFile
	{
		$file = new TestFile()->setTemporaryContent($contents, 'document.txt');
		$em->persist($file);
		$em->flush();

		return $file;
	}
}
