<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Archiver;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Exception\PermanentErrorException;
use ADT\Files\Archiver;
use ADT\Files\Listeners\FileListener;
use ADT\Files\Tests\Fixtures\Entity\TestFile;
use ADT\Files\Tests\Fixtures\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Archiv je typicky soft NFS mount: kdykoli muze vratit chybu a zapis, ktery skoncil jen
 * v cache, se ztrati. Testy proto hlidaji hlavne to, ze lokalni kopie zmizi az ve chvili,
 * kdy archivni je overene cela, a ze kazda chyba necha vse ve stavu pro dalsi pokus.
 */
final class ArchiverTest extends TestCase
{
	private string $baseDir;
	private string $dataDir;
	private string $archiveDir;

	protected function setUp(): void
	{
		$this->baseDir = sys_get_temp_dir() . '/adt-files-archive-test-' . bin2hex(random_bytes(8));
		$this->dataDir = $this->baseDir . '/data';
		$this->archiveDir = $this->baseDir . '/archive';
		mkdir($this->dataDir, 0777, true);
		mkdir($this->archiveDir, 0777, true);
	}

	protected function tearDown(): void
	{
		self::removeDirectory($this->baseDir);
	}

	#[Test]
	public function archivePublishesJobWithEntityClassAndId(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);
		$file = $this->createFile($em);

		$queue = $this->createMock(BackgroundQueue::class);
		$queue->expects(self::once())
			->method('publish')
			->with(
				Archiver::QUEUE_CALLBACK,
				['entityClass' => TestFile::class, 'fileId' => $file->getId()],
				null,
				Archiver::QUEUE_CALLBACK . ':' . TestFile::class . ':' . $file->getId(),
			);

		$this->archiver($em, $queue)->archive($file);
	}

	#[Test]
	public function archiveOfArchivedFileDoesNothing(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);
		$file = $this->createFile($em);
		$this->archiver($em)->processArchive(TestFile::class, $file->getId());

		$queue = $this->createMock(BackgroundQueue::class);
		$queue->expects(self::never())->method('publish');

		$this->archiver($em, $queue)->archive($file);
	}

	#[Test]
	public function archiveRefusesWithoutArchiveDirectory(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		$file = $this->createFile($em);

		$this->expectExceptionMessage('Archive directory is not configured');
		$this->archiver($em)->archive($file);
	}

	#[Test]
	public function fileIsMovedToArchive(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);
		$file = $this->createFile($em, 'archived contents');
		$id = $file->getId();
		$localPath = $file->getPath();

		$this->archiver($em)->processArchive(TestFile::class, $id);

		self::assertFileDoesNotExist($localPath, 'The local copy must be gone once the archive one is in place.');
		self::assertTrue($file->getIsArchived());
		self::assertSame($this->archiveDir . '/' . $file->getFilename(), $file->getPath());

		// a fresh load must find it in the archive too
		$em->clear();
		$reloaded = $em->find(TestFile::class, $id);
		self::assertTrue($reloaded->getIsArchived());
		self::assertSame('archived contents', $reloaded->getContents());
		self::assertSame([$reloaded->getPath()], self::filesIn($this->archiveDir), 'No temporary file may be left behind.');
	}

	#[Test]
	public function privateFileIsArchivedToo(): void
	{
		// the factory uses the data dir for public and private alike, the flag is what matters
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);
		$file = new TestFile()->setTemporaryContent('secret', 'secret.txt')->setIsPrivate(true);
		$em->persist($file);
		$em->flush();

		$this->archiver($em)->processArchive(TestFile::class, $file->getId());

		self::assertTrue($file->getIsArchived());
		self::assertSame('secret', $file->getContents());
	}

	#[Test]
	public function privateFileGoesToPrivateArchiveWhenConfigured(): void
	{
		$privateArchiveDir = $this->baseDir . '/archive-private';
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir, privateArchiveDir: $privateArchiveDir);
		$private = new TestFile()->setTemporaryContent('secret', 'secret.txt')->setIsPrivate(true);
		$em->persist($private);
		$public = $this->createFile($em, 'public');
		$em->flush();
		$privateLocalPath = $private->getPath();

		$this->archiver($em)->processArchive(TestFile::class, $private->getId());
		$this->archiver($em)->processArchive(TestFile::class, $public->getId());

		self::assertSame($privateArchiveDir . '/' . $private->getFilename(), $private->getPath());
		self::assertSame($this->archiveDir . '/' . $public->getFilename(), $public->getPath());

		// a fresh load must look into the private archive too
		$em->clear();
		$reloaded = $em->find(TestFile::class, $private->getId());
		self::assertSame('secret', $reloaded->getContents());

		// a re-run cleaning up a leftover local copy verifies against the private archive
		file_put_contents($privateLocalPath, 'secret');
		$this->archiver($em)->processArchive(TestFile::class, $private->getId());
		self::assertFileDoesNotExist($privateLocalPath);
		self::assertSame([$reloaded->getPath()], self::filesIn($privateArchiveDir));
	}

	#[Test]
	public function processingTwiceIsHarmless(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);
		$file = $this->createFile($em);

		$this->archiver($em)->processArchive(TestFile::class, $file->getId());
		$this->archiver($em)->processArchive(TestFile::class, $file->getId());

		self::assertTrue($file->getIsArchived());
		self::assertSame('contents', $file->getContents());
	}

	#[Test]
	public function deletedFileIsSkipped(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);

		$this->archiver($em)->processArchive(TestFile::class, 999);

		$this->addToAssertionCount(1);
	}

	#[Test]
	public function leftoverLocalCopyIsRemovedOnRetry(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);
		$file = $this->createFile($em);
		$localPath = $file->getPath();
		$this->archiver($em)->processArchive(TestFile::class, $file->getId());

		// the job died after the row was updated but before the local copy was deleted
		copy($file->getPath(), $localPath);

		$this->archiver($em)->processArchive(TestFile::class, $file->getId());

		self::assertFileDoesNotExist($localPath);
		self::assertFileExists($file->getPath());
	}

	#[Test]
	public function unwritableArchiveLeavesEverythingForTheNextAttempt(): void
	{
		// archive dir under a regular file - every write into it fails, like an archive
		// that is not mounted or not answering
		touch($this->baseDir . '/not-a-directory');
		$archiveDir = $this->baseDir . '/not-a-directory/archive';
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $archiveDir);
		$file = $this->createFile($em);
		$localPath = $file->getPath();

		try {
			$this->archiver($em)->processArchive(TestFile::class, $file->getId());
			self::fail('Archiving into an unwritable archive must throw, so that the job is retried.');
		} catch (PermanentErrorException $e) {
			self::fail('An unavailable archive is a temporary error, the job must be retried: ' . $e->getMessage());
		} catch (Exception) {
		}

		self::assertFileExists($localPath, 'The local copy is the only one, it must stay.');
		$em->clear();
		self::assertFalse($em->find(TestFile::class, $file->getId())->getIsArchived());
	}

	#[Test]
	public function corruptedCopyIsNotAccepted(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);
		$file = $this->createFile($em);
		$localPath = $file->getPath();

		// what the database says no longer matches what is on the disk
		$em->createQuery('UPDATE ' . TestFile::class . ' e SET e.hash = :hash WHERE e.id = :id')
			->execute(['hash' => md5('something else'), 'id' => $file->getId()]);
		$em->refresh($file);

		try {
			$this->archiver($em)->processArchive(TestFile::class, $file->getId());
			self::fail('A copy that does not match the original must not be accepted.');
		} catch (Exception $e) {
			self::assertStringContainsString('Hash', $e->getMessage());
		}

		self::assertFileExists($localPath);
		self::assertFalse($file->getIsArchived());
		self::assertFileDoesNotExist($this->archiveDir . '/' . $file->getFilename());
		self::assertSame([], self::filesIn($this->archiveDir), 'The temporary copy must be cleaned up.');
	}

	#[Test]
	public function localCopySurvivesRollback(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);
		$file = $this->createFile($em);
		$id = $file->getId();
		$localPath = $file->getPath();

		$em->beginTransaction();
		$this->archiver($em)->processArchive(TestFile::class, $id);
		self::assertFileExists($localPath, 'The local copy must not be deleted before the commit.');
		$em->rollback();

		$em->clear();
		self::assertFalse($em->find(TestFile::class, $id)->getIsArchived());
		self::assertFileExists($localPath, 'The row points to the local copy again, so it must still be there.');
	}

	#[Test]
	public function archivedFileHasNoPublicUrl(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, archiveDir: $this->archiveDir);
		$file = $this->createFile($em);
		$this->archiver($em)->processArchive(TestFile::class, $file->getId());

		$this->expectExceptionMessage('Archived file has no public URL');
		$file->getUrl();
	}

	private function archiver(EntityManagerInterface $em, ?BackgroundQueue $queue = null): Archiver
	{
		return new Archiver(self::fileListener($em), $em, $queue ?? $this->createStub(BackgroundQueue::class));
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

		self::assertFileExists($file->getPath());

		return $file;
	}

	/**
	 * @return string[]
	 */
	private static function filesIn(string $directory): array
	{
		$files = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $item) {
			if ($item->isFile()) {
				$files[] = $item->getPathname();
			}
		}

		return $files;
	}

	private static function removeDirectory(string $directory): void
	{
		if (!is_dir($directory)) {
			return;
		}

		foreach (scandir($directory) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $directory . '/' . $item;
			is_dir($path) ? self::removeDirectory($path) : unlink($path);
		}

		rmdir($directory);
	}
}
