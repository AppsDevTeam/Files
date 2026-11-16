<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Storage;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\Files\Archiver;
use ADT\Files\Console\MigrateStorageCommand;
use ADT\Files\Listeners\FileListener;
use ADT\Files\Storage\LocalStorage;
use ADT\Files\Storage\Storage;
use ADT\Files\Tests\Fixtures\Entity\TestFile;
use ADT\Files\Tests\Fixtures\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Files saved on the disk with the former configuration, the listener switched to the
 * bucket, the command carries them over.
 */
final class MigrateStorageCommandTest extends S3TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/adt-files-migrate-test-' . bin2hex(random_bytes(8));
		mkdir($this->dir . '/www', 0777, true);
		mkdir($this->dir . '/private', 0777, true);
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
	public function filesAreCopiedToTheStoragesTheListenerUsesNow(): void
	{
		// the former configuration: public, private and an archive on the disk
		$former = EntityManagerFactory::create($this->dir . '/www', archiveDir: $this->dir . '/archive', privateDataDir: $this->dir . '/private');
		$public = $this->createFile($former, 'public');
		$private = $this->createFile($former, 'private', private: true);
		$archived = $this->createFile($former, 'archived');
		$this->archiver($former)->processArchive(TestFile::class, $archived->getId());
		$former->getConnection()->insert('TestFile', ['originalName' => 'x', 'createdAt' => '2024-01-01', 'mimeType' => 'a/b']);

		// the new one: everything in buckets, on the same database
		[$data, $privateData, $archive] = [$this->dataStorage(null, 'www'), $this->dataStorage(null, 'private'), $this->archiveStorage()];
		$em = EntityManagerFactory::create($data, archiveDir: $archive, privateDataDir: $privateData, connection: $former->getConnection());
		$command = new MigrateStorageCommand($em, self::fileListener($em), $this->dir . '/www', $this->dir . '/private', $this->dir . '/archive');

		// a dry run copies nothing
		$tester = new CommandTester($command);
		self::assertSame(1, $tester->execute([]), 'The row without a file is reported.');
		self::assertStringContainsString('Would copy 3 files', $tester->getDisplay());
		self::assertNull($data->getSize($public->getFilename()));

		self::assertSame(1, $tester->execute(['--exec' => true]));
		self::assertStringContainsString('Copied 3 files', $tester->getDisplay());
		self::assertStringContainsString('Rows with no filename', $tester->getDisplay());
		self::assertSame(6, $data->getSize($public->getFilename()));
		self::assertSame(7, $privateData->getSize($private->getFilename()));
		self::assertSame(8, $archive->getSize($archived->getFilename()));
		self::assertFileExists($this->dir . '/www/' . $public->getFilename(), 'Without --delete the source stays.');

		// the application reads them from the bucket now
		$em->clear();
		self::assertSame('public', $em->find(TestFile::class, $public->getId())->getContents());
		self::assertSame('archived', $em->find(TestFile::class, $archived->getId())->getContents());

		// a second run has nothing to copy, with --delete it removes the sources
		self::assertSame(1, $tester->execute(['--exec' => true, '--delete' => true]));
		self::assertStringContainsString('Copied 0 files', $tester->getDisplay());
		self::assertStringContainsString('3 already in the target, 3 deleted', $tester->getDisplay());
		self::assertFileDoesNotExist($this->dir . '/www/' . $public->getFilename());
		self::assertFileDoesNotExist($this->dir . '/archive/' . $archived->getFilename());
		self::assertSame(6, $data->getSize($public->getFilename()));
	}

	#[Test]
	public function sourceThatDoesNotMatchTheRowIsReportedAndTheRestGoesOn(): void
	{
		$former = EntityManagerFactory::create($this->dir . '/www');
		$good = $this->createFile($former, 'good');
		$bad = $this->createFile($former, 'bad');
		$missing = $this->createFile($former, 'missing');
		file_put_contents($this->dir . '/www/' . $bad->getFilename(), 'bad!');
		unlink($this->dir . '/www/' . $missing->getFilename());

		$data = $this->dataStorage();
		$em = EntityManagerFactory::create($data, connection: $former->getConnection());
		$tester = new CommandTester(new MigrateStorageCommand($em, self::fileListener($em), $this->dir . '/www'));

		self::assertSame(1, $tester->execute(['--exec' => true, '--delete' => true]));
		self::assertStringContainsString('Copied 1 files', $tester->getDisplay());
		self::assertStringContainsString('Copy failed', $tester->getDisplay());
		self::assertStringContainsString('Missing in the source (1): ' . $missing->getId(), $tester->getDisplay());
		self::assertSame(4, $data->getSize($good->getFilename()));
		self::assertNull($data->getSize($bad->getFilename()));
		self::assertFileExists($this->dir . '/www/' . $bad->getFilename(), 'A failed copy keeps its source.');
		self::assertFileDoesNotExist($this->dir . '/www/' . $good->getFilename());
	}

	#[Test]
	public function bucketToBucketAndBackToDisk(): void
	{
		$bucket = $this->dataStorage();
		$former = EntityManagerFactory::create($bucket);
		$file = $this->createFile($former, 'roundtrip');

		$em = EntityManagerFactory::create($this->dir . '/www', connection: $former->getConnection());
		$tester = new CommandTester(new MigrateStorageCommand($em, self::fileListener($em), $bucket));
		self::assertSame(0, $tester->execute(['--exec' => true, '--delete' => true]));

		self::assertSame('roundtrip', file_get_contents($this->dir . '/www/' . $file->getFilename()));
		self::assertNull($bucket->getSize($file->getFilename()));
	}

	#[Test]
	public function sameStorageOnBothSidesIsSkipped(): void
	{
		$em = EntityManagerFactory::create($this->dir . '/www');
		$this->createFile($em);
		$tester = new CommandTester(new MigrateStorageCommand($em, self::fileListener($em), $this->dir . '/www'));

		self::assertSame(0, $tester->execute(['--exec' => true, '--delete' => true]));
		self::assertStringContainsString('1 rows whose source and target are the same storage', $tester->getDisplay());
		self::assertCount(1, iterator_to_array(new LocalStorage($this->dir . '/www')->list()), 'Nothing may be deleted.');
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

	private function createFile(EntityManagerInterface $em, string $contents = 'contents', bool $private = false): TestFile
	{
		$file = new TestFile()->setTemporaryContent($contents, 'document.txt')->setIsPrivate($private);
		$em->persist($file);
		$em->flush();

		return $file;
	}
}
