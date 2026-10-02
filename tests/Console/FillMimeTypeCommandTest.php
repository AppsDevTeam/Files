<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Console;

use ADT\Files\Console\FillMimeTypeCommand;
use ADT\Files\Helpers;
use ADT\Files\Tests\Fixtures\Entity\TestFile;
use ADT\Files\Tests\Fixtures\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The mime type is only written on upload, so every file saved before the column existed has
 * it empty and anything reading it - a FileResponse content type, for instance - gets a null.
 */
final class FillMimeTypeCommandTest extends TestCase
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
	public function emptyMimeTypeIsDetectedFromTheFile(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, nullableMimeType: true);
		$id = $this->createFileWithoutMimeType($em, 'document.txt');

		$tester = $this->runCommand($em);

		self::assertSame('text/plain', $em->find(TestFile::class, $id)->getMimeType());
		self::assertStringContainsString('1 detected from the file, 0 left at', $tester->getDisplay());
	}

	/**
	 * The migration that makes the column not nullable has to put something into the rows
	 * that are still empty, and the only honest value is the default one. That must not
	 * cost the command its work: run in that order, it still has to find the real types.
	 */
	#[Test]
	public function rowsHoldingTheDefaultAreExaminedAgain(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, nullableMimeType: true);
		$id = $this->createFileWithoutMimeType($em, 'document.txt');

		// what the migration does to every row left without a mime type
		$em->createQuery('UPDATE ' . TestFile::class . ' e SET e.mimeType = :mimeType')
			->setParameter('mimeType', Helpers::DEFAULT_MIME_TYPE)
			->execute();
		$em->clear();

		$tester = $this->runCommand($em);

		self::assertSame('text/plain', $em->find(TestFile::class, $id)->getMimeType());
		self::assertStringContainsString('1 detected from the file', $tester->getDisplay());
	}

	#[Test]
	public function rowThatStaysUnknownIsNotRewrittenWithTheSameValue(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, nullableMimeType: true);
		$this->createFileWithoutMimeType($em, 'missing.txt', deleteFile: true);

		$this->runCommand($em);
		$tester = $this->runCommand($em);

		// second run finds it again, has nothing better to say, and leaves it alone
		self::assertStringContainsString('0 detected from the file', $tester->getDisplay());
		self::assertStringContainsString('1 already had the value', $tester->getDisplay());
	}

	#[Test]
	public function realMimeTypeIsNeverReExamined(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, nullableMimeType: true);

		$file = new TestFile()->setTemporaryContent('contents', 'document.txt');
		$em->persist($file);
		$em->flush();
		$em->clear();

		$tester = $this->runCommand($em);

		self::assertStringContainsString('0 rows with no usable mime type', $tester->getDisplay());
	}

	#[Test]
	public function alreadyFilledInMimeTypeIsLeftAlone(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, nullableMimeType: true);

		$file = new TestFile()->setTemporaryContent('contents', 'document.txt');
		$em->persist($file);
		$em->flush();
		$id = $file->getId();

		// a lie on purpose - if the command rewrote it, the row would be text/plain again
		$em->createQuery('UPDATE ' . TestFile::class . ' e SET e.mimeType = :mimeType WHERE e.id = :id')
			->setParameters(['mimeType' => 'application/custom', 'id' => $id])
			->execute();
		$em->clear();

		$this->runCommand($em);

		self::assertSame('application/custom', $em->find(TestFile::class, $id)->getMimeType());
	}

	#[Test]
	public function missingFileIsReportedAndTheRestIsStillProcessed(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, nullableMimeType: true);
		$missingId = $this->createFileWithoutMimeType($em, 'missing.txt', deleteFile: true);
		$id = $this->createFileWithoutMimeType($em, 'document.txt');

		$tester = $this->runCommand($em);

		// the column is not nullable, so even a row whose file is gone has to end up with
		// something - otherwise it alone would block the migration
		self::assertSame(Helpers::DEFAULT_MIME_TYPE, $em->find(TestFile::class, $missingId)->getMimeType());
		self::assertSame('text/plain', $em->find(TestFile::class, $id)->getMimeType());
		self::assertStringContainsString('1 detected from the file, 1 left at', $tester->getDisplay());
		self::assertStringContainsString('file is missing', $tester->getDisplay());
	}

	#[Test]
	public function dryRunReportsWithoutWriting(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, nullableMimeType: true);
		$id = $this->createFileWithoutMimeType($em, 'document.txt');

		$tester = $this->runCommand($em, ['--dry-run' => true]);

		// read around the entity manager - hydrating a row that still has NULL would fail on
		// the not nullable property, which is exactly the state a dry run has to leave behind
		self::assertNull($this->readRawMimeType($em, $id));
		self::assertStringContainsString('1 detected from the file', $tester->getDisplay());
	}

	#[Test]
	public function batchesSmallerThanTheResultSetProcessEverything(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, nullableMimeType: true);
		$ids = [
			$this->createFileWithoutMimeType($em, 'a.txt'),
			$this->createFileWithoutMimeType($em, 'b.txt'),
			$this->createFileWithoutMimeType($em, 'c.txt'),
		];

		$this->runCommand($em, ['--batch-size' => '2']);

		foreach ($ids as $id) {
			self::assertSame('text/plain', $em->find(TestFile::class, $id)->getMimeType());
		}
	}

	#[Test]
	public function unknownEntityFails(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, nullableMimeType: true);

		$tester = $this->runCommand($em, ['--entity' => 'App\Nonsense']);

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('is not a mapped entity', $tester->getDisplay());
	}

	/**
	 * @param array<string, string|bool> $input
	 */
	private function runCommand(EntityManagerInterface $em, array $input = []): CommandTester
	{
		$tester = new CommandTester(new FillMimeTypeCommand($em, $this->dataDir, $this->dataDir));
		$tester->execute($input);

		$em->clear();

		return $tester;
	}

	private function readRawMimeType(EntityManagerInterface $em, mixed $id): ?string
	{
		$metadata = $em->getClassMetadata(TestFile::class);
		$column = $metadata->getColumnName('mimeType');

		return $em->getConnection()->fetchOne('SELECT ' . $column . ' FROM ' . $metadata->getTableName() . ' WHERE id = ?', [$id]) ?: null;
	}

	private function createFileWithoutMimeType(EntityManagerInterface $em, string $name, bool $deleteFile = false): mixed
	{
		$file = new TestFile()->setTemporaryContent('contents', $name);
		$em->persist($file);
		$em->flush();

		$id = $file->getId();

		if ($deleteFile) {
			unlink($file->getPath());
		}

		// the listener fills the mime type in on upload, this is what the old rows look like
		$em->createQuery('UPDATE ' . TestFile::class . ' e SET e.mimeType = NULL WHERE e.id = :id')
			->setParameter('id', $id)
			->execute();
		$em->clear();

		return $id;
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
