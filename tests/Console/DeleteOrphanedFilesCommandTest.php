<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Console;

use ADT\Files\Console\DeleteOrphanedFilesCommand;
use ADT\Files\Tests\Fixtures\Entity\TestFile;
use ADT\Files\Tests\Fixtures\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rows can lose their file - that is what FillMimeTypeCommand reports. The imbalance also
 * goes the other way: a file whose row is gone stays on the disk forever, taking space
 * nobody can account for.
 *
 * This is the one command in the library that destroys something, so most of what is pinned
 * down here is what it must NOT delete.
 */
final class DeleteOrphanedFilesCommandTest extends TestCase
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
	public function withoutExecNothingIsDeleted(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		$this->createFile($em);
		$orphan = $this->createOrphan('orphan.txt');

		$tester = $this->runCommand($em);

		self::assertFileExists($orphan);
		self::assertStringContainsString('Would delete 1 orphaned files', $tester->getDisplay());
		self::assertStringContainsString('orphan.txt', $tester->getDisplay());
	}

	#[Test]
	public function execDeletesTheOrphanAndKeepsTheRest(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		$kept = $this->createFile($em);
		$orphan = $this->createOrphan('orphan.txt');

		$tester = $this->runCommand($em, ['--exec' => true]);

		self::assertFileDoesNotExist($orphan);
		self::assertFileExists($kept, 'A file a row points to must survive.');
		self::assertStringContainsString('Deleted 1 orphaned files', $tester->getDisplay());
	}

	#[Test]
	public function orphanInASubdirectoryIsFound(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		$this->createFile($em);

		// from id 1000 up Helpers::getName() splits the name into directories, so the scan
		// has to recurse and compare whole relative paths, not basenames
		$orphan = $this->createOrphan('165/77_g4jg4_document.txt');

		$this->runCommand($em, ['--exec' => true]);

		self::assertFileDoesNotExist($orphan);
	}

	#[Test]
	public function fileTheApplicationGeneratedItselfIsAnOrphanToo(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		$kept = $this->createFile($em);
		$thumbnail = $this->createThumbnail($kept);

		$this->runCommand($em, ['--exec' => true]);

		// not a wish, a warning: this command knows about rows and nothing else, so anything
		// an application writes into the data directory on its own is an orphan to it
		self::assertFileDoesNotExist($thumbnail);
	}

	#[Test]
	public function recentlyWrittenFileIsLeftAlone(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		$this->createFile($em);
		$fresh = $this->createOrphan('fresh.txt', age: 0);

		$this->runCommand($em, ['--exec' => true]);

		// its row may still be waiting for a commit - deleting it would break an upload
		// that is about to succeed
		self::assertFileExists($fresh);
	}

	#[Test]
	public function minAgeZeroTakesEvenAFreshFile(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		$this->createFile($em);
		$fresh = $this->createOrphan('fresh.txt', age: 0);

		$this->runCommand($em, ['--exec' => true, '--min-age' => '0']);

		self::assertFileDoesNotExist($fresh);
	}

	#[Test]
	public function emptyTableStopsTheCommand(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);
		$orphan = $this->createOrphan('orphan.txt');

		$tester = $this->runCommand($em, ['--exec' => true]);

		// an empty table next to a full directory is a misconfigured data dir far more
		// often than a storage that genuinely has nothing left to keep
		self::assertFileExists($orphan);
		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('misconfiguration', $tester->getDisplay());
	}

	/**
	 * @param array<string, string|bool|string[]> $input
	 */
	private function runCommand(EntityManagerInterface $em, array $input = []): CommandTester
	{
		$tester = new CommandTester(new DeleteOrphanedFilesCommand($em, $this->dataDir, $this->dataDir));
		$tester->execute($input);

		return $tester;
	}

	private function createFile(EntityManagerInterface $em): string
	{
		$file = new TestFile()->setTemporaryContent('contents', 'document.txt');
		$em->persist($file);
		$em->flush();

		return $file->getPath();
	}

	private function createThumbnail(string $path): string
	{
		$thumbnail = dirname($path) . '/' . pathinfo($path, PATHINFO_FILENAME) . '_thumb.webp';
		file_put_contents($thumbnail, 'thumb');
		touch($thumbnail, time() - 172800);

		return $thumbnail;
	}

	private function createOrphan(string $name, int $age = 172800): string
	{
		$path = $this->dataDir . '/' . $name;

		if (!is_dir(dirname($path))) {
			mkdir(dirname($path), 0777, true);
		}

		file_put_contents($path, 'contents');
		touch($path, time() - $age);

		return $path;
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
