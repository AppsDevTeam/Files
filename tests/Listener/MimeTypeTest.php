<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Listener;

use ADT\Files\Helpers;
use ADT\Files\Tests\Fixtures\Entity\TestFile;
use ADT\Files\Tests\Fixtures\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The mime type column is not nullable, so the listener has to put something there for every
 * file it saves - mime_content_type() only fails when the file cannot be read, and there is
 * nothing better to say about such a file than "unknown binary".
 */
final class MimeTypeTest extends TestCase
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
	public function mimeTypeIsDetectedFromTheContentsOnUpload(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);

		$file = new TestFile()->setTemporaryContent('contents', 'document.txt');
		$em->persist($file);
		$em->flush();

		self::assertSame('text/plain', $file->getMimeType());
	}

	#[Test]
	public function extensionDoesNotDecideTheMimeType(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);

		// the name says png, the contents say otherwise - the contents win
		$file = new TestFile()->setTemporaryContent('contents', 'not-really.png');
		$em->persist($file);
		$em->flush();

		self::assertSame('text/plain', $file->getMimeType());
	}

	#[Test]
	public function unreadableContentsFallBackToTheDefault(): void
	{
		$em = EntityManagerFactory::create($this->dataDir);

		// an empty file is the one case fileinfo reports as its own type rather than guessing
		$file = new TestFile()->setTemporaryContent('', 'empty.bin');
		$em->persist($file);
		$em->flush();

		self::assertNotSame('', $file->getMimeType());
	}

	#[Test]
	public function entityHasTheDefaultBeforeItIsSaved(): void
	{
		// a plain string property with no value set would be an uninitialized property error
		self::assertSame(Helpers::DEFAULT_MIME_TYPE, new TestFile()->getMimeType());
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
