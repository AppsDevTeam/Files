<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Helpers;

use ADT\Files\Helpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Callers usually have nothing but a blob of bytes - an API endpoint taking base64, a
 * generated document - and a name they made up. Letting them guess the extension ends with
 * a png stored as shift_file.pdf, so the contents decide.
 *
 * The mime type to extension table comes from symfony/mime. These tests are not there to
 * re-test it, but to pin down what this library adds on top: the fallback and the fact that
 * no detected content can talk its way into an extension the application blocks.
 */
final class DetectExtensionTest extends TestCase
{
	/** @var array<string, string> */
	private array $originalExtensions;
	/** @var string[] */
	private array $originalBlocked;

	protected function setUp(): void
	{
		$this->originalExtensions = Helpers::$extensions;
		$this->originalBlocked = Helpers::$blockedExtensions;
	}

	protected function tearDown(): void
	{
		Helpers::$extensions = $this->originalExtensions;
		Helpers::$blockedExtensions = $this->originalBlocked;
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function contents(): array
	{
		return [
			'png' => [base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'png'],
			'pdf' => ["%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'pdf'],
			'gif' => ["GIF89a\x01\x00\x01\x00\x00\xff\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x00;", 'gif'],
			'plain text' => ['just some text', 'txt'],
			'unknown binary' => ["\x00\x01\x02\xff\xfe", Helpers::DEFAULT_EXTENSION],
			'empty' => ['', Helpers::DEFAULT_EXTENSION],
		];
	}

	#[Test]
	#[DataProvider('contents')]
	public function extensionFollowsTheContents(string $contents, string $expected): void
	{
		self::assertSame($expected, Helpers::detectExtension($contents));
	}

	#[Test]
	public function aMappingOntoABlockedExtensionIsIgnored(): void
	{
		// an application is free to extend the map, but not to open the door the blocked
		// list exists to keep shut
		Helpers::$extensions['text/plain'] = 'php';

		self::assertSame(Helpers::DEFAULT_EXTENSION, Helpers::detectExtension('just some text'));
	}

	#[Test]
	public function phpSourceNeverGetsAnExecutableExtension(): void
	{
		self::assertSame(Helpers::DEFAULT_EXTENSION, Helpers::detectExtension("<?php\n echo 1;\n"));
	}

	#[Test]
	public function extensionBlockedByTheApplicationFallsBackToTheDefault(): void
	{
		$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';

		self::assertSame('svg', Helpers::detectExtension($svg));

		// the readme tells applications serving files from a server that executes more than
		// php to block svg - doing so has to take the extension off the table here too
		Helpers::$blockedExtensions[] = 'svg';

		self::assertSame(Helpers::DEFAULT_EXTENSION, Helpers::detectExtension($svg));
	}

	#[Test]
	public function applicationCanExtendTheMap(): void
	{
		Helpers::$extensions['text/plain'] = 'log';

		self::assertSame('log', Helpers::detectExtension('just some text'));
	}

	#[Test]
	public function nameWithoutAnExtensionGetsOne(): void
	{
		self::assertSame('shift_file.txt', Helpers::getNameByContents('just some text', 'shift_file'));
	}

	#[Test]
	public function wrongExtensionInTheNameIsReplaced(): void
	{
		self::assertSame('shift_file.txt', Helpers::getNameByContents('just some text', 'shift_file.pdf'));
	}

	#[Test]
	public function nameWithDotsKeepsEverythingButTheLastExtension(): void
	{
		self::assertSame('shift.2026-01.txt', Helpers::getNameByContents('just some text', 'shift.2026-01.pdf'));
	}
}
