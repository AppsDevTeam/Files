<?php

declare(strict_types=1);

namespace ADT\Files\Tests\ContentImages;

use ADT\Files\ContentImages\StreamDownloader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamDownloaderTest extends TestCase
{
	/** @var resource|null */
	private static $server = null;

	private static int $port;

	public static function setUpBeforeClass(): void
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		self::$port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
		fclose($socket);

		self::$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/stream-server.php'],
			[['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
			$pipes,
		);

		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', self::$port); $i++) {
			usleep(100_000);
		}
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$server) {
			proc_terminate(self::$server);
		}
	}

	private static function url(string $path, string $host = '127.0.0.1'): string
	{
		return 'http://' . $host . ':' . self::$port . $path;
	}

	#[Test]
	public function internalAddressesAreRefused(): void
	{
		$downloader = new StreamDownloader(timeout: 2);

		foreach ([
			self::url('/image.png'),
			self::url('/image.png', 'localhost'),
			'http://10.0.0.1/image.png',
			'http://192.168.1.1/image.png',
			'http://169.254.169.254/latest/meta-data/',
			'http://100.100.100.200/latest/meta-data/',
			'http://[::1]/image.png',
			'http://[::ffff:127.0.0.1]/image.png',
			'http://0.0.0.0/image.png',
		] as $url) {
			self::assertNull($downloader->download($url, 1024), $url);
		}
	}

	#[Test]
	public function onlyPlainHttpUrlsAreDownloaded(): void
	{
		$downloader = new StreamDownloader(timeout: 2, allowPrivateNetworks: true);

		self::assertNull($downloader->download('file:///etc/passwd', 1024));
		self::assertNull($downloader->download('ftp://127.0.0.1/image.png', 1024));
		self::assertNull($downloader->download('http://user:secret@127.0.0.1:' . self::$port . '/image.png', 1024));
	}

	#[Test]
	public function imageIsDownloadedThroughRedirects(): void
	{
		$downloader = new StreamDownloader(timeout: 2, allowPrivateNetworks: true);

		self::assertSame("\x89PNG\r\n\x1a\nimage", $downloader->download(self::url('/image.png'), 1024));
		self::assertSame("\x89PNG\r\n\x1a\nimage", $downloader->download(self::url('/redirect'), 1024));
		self::assertNull($downloader->download(self::url('/loop'), 1024));
		self::assertNull($downloader->download(self::url('/missing'), 1024));
		self::assertNull($downloader->download(self::url('/big'), 1024));
	}

	#[Test]
	public function requestGoesToTheCheckedAddressWithTheOriginalHost(): void
	{
		$downloader = new StreamDownloader(timeout: 2, allowPrivateNetworks: true);

		self::assertSame('localhost:' . self::$port, $downloader->download(self::url('/host', 'localhost'), 1024));
	}
}
