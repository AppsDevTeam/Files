<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Listener;

use ADT\Files\Tests\Fixtures\Entity\TestDocument;
use ADT\Files\Tests\Fixtures\Entity\TestFile;
use ADT\Files\Tests\Fixtures\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The listener deletes files in postFlush, which Doctrine dispatches while the transaction
 * is still open. Deleting there means a later rollback restores the row but not the file,
 * leaving an entity pointing at a path that no longer exists.
 */
final class DeleteFileAfterCommitTest extends TestCase
{
	private string $dataDir;

	protected function setUp(): void
	{
		$this->dataDir = sys_get_temp_dir() . '/adt-files-test-' . bin2hex(random_bytes(8));
		mkdir($this->dataDir, 0777, true);
	}

	protected function tearDown(): void
	{
		self::removeDirectory($this->dataDir);
	}

	#[Test]
	public function fileSurvivesUntilTheOuterTransactionCommits(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		[$file, $path] = $this->createFile($em);

		$em->beginTransaction();
		$em->remove($file);
		$em->flush();

		self::assertFileExists($path, 'The file must not be deleted before the transaction commits.');

		$em->commit();

		self::assertFileDoesNotExist($path, 'The file must be deleted once the transaction commits.');
	}

	#[Test]
	public function fileSurvivesRollbackTogetherWithItsRow(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		[$file, $path] = $this->createFile($em);
		$id = $file->getId();

		$em->beginTransaction();
		$em->remove($file);
		$em->flush();
		$em->rollback();

		$em->clear();

		self::assertNotNull($em->find(TestFile::class, $id), 'The rollback must bring the row back.');
		self::assertFileExists($path, 'The row is back, so the file must still be there too.');
	}

	#[Test]
	public function fileIsDeletedWhenThereIsNoOuterTransaction(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		[$file, $path] = $this->createFile($em);

		// flush() opens and commits its own transaction, so the callback fires right away
		$em->remove($file);
		$em->flush();

		self::assertFileDoesNotExist($path);
	}

	#[Test]
	public function orphanedFileSurvivesRollback(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);

		$document = new TestDocument()->setFile($this->newFile('old'));
		$em->persist($document);
		$em->flush();
		$oldPath = $document->getFile()->getPath();

		// this is the real world case: nothing calls remove(), the old file is dropped
		// only because orphanRemoval kicks in when the relation is reassigned
		$em->beginTransaction();
		$document->setFile($this->newFile('new'));
		$em->flush();

		self::assertFileExists($oldPath, 'The orphaned file must not be deleted before the commit.');

		$em->rollback();

		self::assertFileExists($oldPath, 'The rollback restored the relation, so the file must stay.');
	}

	#[Test]
	public function plainEntityManagerDeletesWhenNoTransactionIsOpen(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, adt: false);
		[$file, $path] = $this->createFile($em);

		$em->remove($file);
		$em->flush();

		self::assertFileDoesNotExist($path);
	}

	#[Test]
	public function plainEntityManagerKeepsFileWhenATransactionIsOpen(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, adt: false);
		[$file, $path] = $this->createFile($em);

		// Without TransactionCallbacksInterface there is no way to learn about the commit,
		// so the file is kept - an unused file can be cleaned up, a missing one cannot.
		$em->beginTransaction();
		$em->remove($file);
		$em->flush();
		$em->commit();

		self::assertFileExists($path);
	}

	/**
	 * @return array{TestFile, string}
	 */
	private function createFile(EntityManagerInterface $em): array
	{
		$file = $this->newFile();
		$em->persist($file);
		$em->flush();

		$path = $file->getPath();
		self::assertFileExists($path);

		return [$file, $path];
	}

	private function newFile(string $contents = 'contents'): TestFile
	{
		return new TestFile()->setTemporaryContent($contents, 'document.txt');
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
