<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Listener;

use ADT\Files\Tests\Fixtures\Entity\TestDocument;
use ADT\Files\Tests\Fixtures\Entity\TestFile;
use ADT\Files\Tests\Fixtures\Entity\TestSoftDeleteDocument;
use ADT\Files\Tests\Fixtures\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * remove() of an owner cascades to its file (orphanRemoval), so preRemove of the file is
 * dispatched and the listener schedules its deletion. When the owner is soft deleteable,
 * SoftDeleteable persists it again in onFlush, Doctrine cancels the removal of the file
 * and its row stays - the stored file has to stay too, otherwise the row points nowhere.
 */
final class SoftDeletedOwnerKeepsFileTest extends TestCase
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

	/**
	 * @return array<string, array{bool}>
	 */
	public static function softDeleteableModes(): array
	{
		return [
			'default listener' => [false],
			'listener detaching in postFlush' => [true],
		];
	}

	#[Test]
	#[DataProvider('softDeleteableModes')]
	public function fileOfSoftDeletedOwnerStays(bool $handlePostFlushEvent): void
	{
		$em = $this->createEntityManager($handlePostFlushEvent);
		[$document, $fileId, $path] = $this->createDocument($em);

		$em->remove($document);
		$em->flush();

		self::assertNotNull($document->getDeletedAt(), 'The owner must be soft deleted.');
		$em->clear();
		self::assertNotNull($em->find(TestFile::class, $fileId), 'The row of the file must stay.');
		self::assertFileExists($path, 'The row stays, so the stored file must stay too.');
	}

	#[Test]
	#[DataProvider('softDeleteableModes')]
	public function fileOfSoftDeletedOwnerStaysAfterTheOuterCommit(bool $handlePostFlushEvent): void
	{
		$em = $this->createEntityManager($handlePostFlushEvent);
		[$document, , $path] = $this->createDocument($em);

		$em->beginTransaction();
		$em->remove($document);
		$em->flush();
		$em->commit();

		self::assertFileExists($path, 'The deletion is deferred to the commit, it must not run for a kept row either.');
	}

	#[Test]
	public function fileOfDeletedOwnerIsDeleted(): void
	{
		$em = $this->createEntityManager(false);
		$document = new TestDocument()->setFile(new TestFile()->setTemporaryContent('contents', 'document.txt'));
		$em->persist($document);
		$em->flush();
		$path = $document->getFile()->getPath();
		self::assertFileExists($path);

		// an owner that is not soft deleteable goes for good, and its file with it
		$em->remove($document);
		$em->flush();

		self::assertFileDoesNotExist($path);
	}

	private function createEntityManager(bool $handlePostFlushEvent): EntityManagerInterface
	{
		$em = EntityManagerFactory::create($this->dataDir);
		$em->getEventManager()->addEventSubscriber(new SoftDeleteableListener(handlePostFlushEvent: $handlePostFlushEvent));
		return $em;
	}

	/**
	 * @return array{TestSoftDeleteDocument, int, string}
	 */
	private function createDocument(EntityManagerInterface $em): array
	{
		$document = new TestSoftDeleteDocument()->setFile(new TestFile()->setTemporaryContent('contents', 'document.txt'));
		$em->persist($document);
		$em->flush();

		$path = $document->getFile()->getPath();
		self::assertFileExists($path);

		return [$document, $document->getFile()->getId(), $path];
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
