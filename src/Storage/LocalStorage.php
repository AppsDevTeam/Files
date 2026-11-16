<?php

declare(strict_types=1);

namespace ADT\Files\Storage;

use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Adresar na disku - datovy adresar aplikace nebo archiv na NFS mountu.
 *
 * Archiv byva soft NFS mount: kdykoli muze vratit chybu a zapis, ktery skoncil jen v cache
 * klienta, se pri vypadku ztrati, aniz by o tom write()/close() vedely. put() proto kopiruje
 * do docasneho souboru, fsync()uje s kontrolou, overi velikost a hash proti zdroji a teprve
 * pak prejmenuje na finalni jmeno. Na lokalnim disku je to o fsync navic, coz za jistotu stoji.
 */
class LocalStorage implements Storage
{
	public function __construct(
		protected string $directory,
		protected ?string $url = null,
	) {
		$this->directory = rtrim($directory, '/');
		$this->url = $url !== null ? rtrim($url, '/') : null;
	}

	public function getDirectory(): string
	{
		return $this->directory;
	}

	public function put(string $name, string $localPath, ?string $contentType = null, ?string $md5 = null): void
	{
		$target = $this->getPath($name);

		// we don't use file_exists because it's not an atomic operation, that's why we rather use @
		if (@mkdir(dirname($target), 0770, true)) {
			// umask is applied to the permissions in mkdir, the default 0022 would make it 0750
			chmod(dirname($target), 0770);
		}

		$tmp = $target . '.tmp-' . bin2hex(random_bytes(4));

		try {
			$in = @fopen($localPath, 'rb');
			if ($in === false) {
				throw new Exception('Cannot open ' . $localPath . ' for reading.');
			}

			$out = @fopen($tmp, 'xb');
			if ($out === false) {
				fclose($in);
				throw new Exception('Cannot create ' . $tmp . '.');
			}

			$copied = stream_copy_to_stream($in, $out);
			fclose($in);

			// na soft NFS mountu se chyba zapisu muze ukazat az pri fsync/close - kontrola
			// kazdeho kroku je to jedine, co dava jistotu, ze data jsou na serveru
			if ($copied === false || !fflush($out) || !fsync($out)) {
				fclose($out);
				throw new Exception('Writing ' . $tmp . ' failed.');
			}
			if (!fclose($out)) {
				throw new Exception('Closing ' . $tmp . ' failed.');
			}

			clearstatcache(true, $tmp);
			$md5 ??= @md5_file($localPath);
			if (@filesize($tmp) !== @filesize($localPath) || $md5 === false || @md5_file($tmp) !== $md5) {
				throw new Exception('Copy ' . $tmp . ' does not match ' . $localPath . '.');
			}

			if (!@rename($tmp, $target)) {
				throw new Exception('Cannot rename ' . $tmp . ' to ' . $target . '.');
			}
			@chmod($target, 0660);
		} catch (Throwable $e) {
			@unlink($tmp);
			throw $e;
		}
	}

	public function read(string $name)
	{
		$path = $this->getPath($name);
		$handle = @fopen($path, 'rb');

		if ($handle === false) {
			throw new Exception('Cannot open ' . $path . ' for reading.');
		}

		return $handle;
	}

	public function getSize(string $name): ?int
	{
		$path = $this->getPath($name);
		clearstatcache(true, $path);

		return is_file($path) ? (@filesize($path) ?: 0) : null;
	}

	public function delete(string $name): void
	{
		$path = $this->getPath($name);

		if (!@unlink($path) && is_file($path)) {
			throw new Exception('Cannot delete ' . $path . '.');
		}
	}

	public function getPath(string $name): string
	{
		return $this->directory . '/' . $name;
	}

	public function getUrl(string $name): ?string
	{
		return $this->url !== null ? $this->url . '/' . $name : null;
	}

	public function list(): iterable
	{
		if (!is_dir($this->directory)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile()) {
				yield substr($file->getPathname(), strlen($this->directory) + 1) => [$file->getSize(), $file->getMTime()];
			}
		}
	}

	/**
	 * Jmena souboru jsou rozsekana do adresaru po id, takze po uklidu zbyva prazdna kostra.
	 * Maze zdola nahoru, nikdy samotny adresar uloziste. Vraci pocet smazanych.
	 */
	public function removeEmptyDirectories(): int
	{
		if (!is_dir($this->directory)) {
			return 0;
		}

		$removed = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $file) {
			// @rmdir misto kontroly obsahu - na neprazdnem selze a je to atomicke
			if ($file->isDir() && @rmdir($file->getPathname())) {
				$removed++;
			}
		}

		return $removed;
	}
}
