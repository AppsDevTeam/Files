<?php

declare(strict_types=1);

namespace ADT\Files;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Exception\PermanentErrorException;
use ADT\DoctrineComponents\TransactionCallbacksInterface;
use ADT\Files\Entities\File;
use ADT\Files\Listeners\FileListener;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Throwable;

/**
 * Presun souboru z datoveho adresare do archivu ($archiveDir ve FileListeneru, typicky
 * NFS mount). archive() jen zalozi background job, samotny presun dela processArchive().
 *
 * Archiv muze byt chvili nedostupny a soft NFS mount pak vraci chyby misto zaseknuti -
 * ale zapis, ktery se do souboru dostal jen do cache klienta, se pri vypadku ztrati
 * a write()/close() o tom nemusi vedet. Proto: kopie do docasneho souboru, fsync()
 * s kontrolou, overeni velikosti a hashe, teprve pak rename na finalni jmeno, priznak
 * v DB a smazani lokalni kopie (az po commitu). Jakakoli chyba = vyjimka = background
 * queue job zopakuje pozdeji; processArchive() je proto idempotentni.
 *
 * Registrace queue callbacku v projektu:
 *   backgroundQueue: callbacks: archiveFile: [@ADT\Files\Archiver, processArchive]
 */
class Archiver
{
	public const string QUEUE_CALLBACK = 'archiveFile';

	public function __construct(
		protected FileListener $fileListener,
		protected EntityManagerInterface $em,
		protected BackgroundQueue $backgroundQueue,
	) {
	}

	/**
	 * Zalozi job, ktery soubor presune do archivu. Uvnitr transakce se job odesle az po
	 * commitu (background-queue), takze rollback archivaci zrusi taky.
	 * @throws Exception
	 */
	public function archive(File $file): void
	{
		if ($this->fileListener->getArchiveDir() === null) {
			throw new Exception('Archive directory is not configured, see FileListener $archiveDir.');
		}

		if ($file->getIsArchived()) {
			return;
		}

		if (!$file->getId()) {
			throw new Exception('Only a saved file can be archived.');
		}

		// realna trida, ne lazy proxy - z parametru jobu se entita v consumeru nacita
		$entityClass = $this->em->getClassMetadata($file::class)->getName();

		$this->backgroundQueue->publish(
			self::QUEUE_CALLBACK,
			['entityClass' => $entityClass, 'fileId' => $file->getId()],
			identifier: self::QUEUE_CALLBACK . ':' . $entityClass . ':' . $file->getId(),
		);
	}

	/**
	 * Callback background jobu. Nazvy parametru jsou klice parametru jobu.
	 * @param class-string<File> $entityClass
	 * @throws Exception
	 */
	public function processArchive(string $entityClass, int $fileId): void
	{
		$archiveDir = $this->fileListener->getArchiveDir();
		if ($archiveDir === null) {
			throw new PermanentErrorException('Archive directory is not configured, see FileListener $archiveDir.');
		}

		$file = $this->em->find($entityClass, $fileId);

		// mezitim smazany soubor uz neni co archivovat
		if (!$file) {
			return;
		}

		if (!$file instanceof File) {
			throw new PermanentErrorException($entityClass . ' does not implement ' . File::class . '.');
		}

		$target = $archiveDir . '/' . $file->getFilename();

		if ($file->getIsArchived()) {
			// Opakovany beh po padu mezi zapisem do DB a smazanim lokalni kopie - entita uz
			// ukazuje do archivu, lokalni kopii dohledame v datovem adresari.
			$this->deleteLocalCopy($file, $target);
			return;
		}

		$source = $file->getPath();
		if (!is_file($source)) {
			throw new PermanentErrorException('File ' . $source . ' to archive does not exist.');
		}

		$this->copyVerified($source, $target, $file);

		$this->em->createQuery('UPDATE ' . $entityClass . ' e SET e.isArchived = true WHERE e.id = :id')
			->setParameter('id', $fileId)
			->execute();

		// stejne jako mazani ve FileListeneru: lokalni kopie jde pryc az po commitu, rollback
		// by jinak vratil radek ukazujici do datoveho adresare, kde uz nic neni
		if ($this->em instanceof TransactionCallbacksInterface) {
			$this->em->afterCommit(fn () => @unlink($source));
		} elseif (!$this->em->getConnection()->isTransactionActive()) {
			@unlink($source);
		}
		// Jinak lokalni kopie zustane lezet - nadbytecny soubor se da uklidit, chybejici ne.

		// low level update - entita v unit of work musi videt novy stav (a FileListener ji
		// v postLoad nastavi cestu do archivu)
		$this->em->refresh($file);
	}

	/**
	 * Zkopiruje soubor do archivu tak, aby na finalni ceste nikdy nebyl nedopsany soubor:
	 * docasny soubor, fsync s kontrolou, overeni, rename.
	 * @throws Exception
	 */
	protected function copyVerified(string $source, string $target, File $file): void
	{
		// we don't use file_exists because it's not an atomic operation, see FileListener::saveFile()
		if (@mkdir(dirname($target), 0770, true)) {
			chmod(dirname($target), 0770);
		}

		$tmp = $target . '.tmp-' . bin2hex(random_bytes(4));

		try {
			$in = @fopen($source, 'rb');
			if ($in === false) {
				throw new Exception('Cannot open ' . $source . ' for reading.');
			}

			$out = @fopen($tmp, 'xb');
			if ($out === false) {
				fclose($in);
				throw new Exception('Cannot create ' . $tmp . '.');
			}

			$copied = stream_copy_to_stream($in, $out);
			fclose($in);

			// Na soft NFS mountu se chyba zapisu muze ukazat az pri fsync/close - kontrola
			// kazdeho kroku je to jedine, co dava jistotu, ze data jsou na serveru.
			if ($copied === false || !fflush($out) || !fsync($out)) {
				fclose($out);
				throw new Exception('Writing ' . $tmp . ' failed.');
			}
			if (!fclose($out)) {
				throw new Exception('Closing ' . $tmp . ' failed.');
			}

			$this->verify($tmp, $source, $file);

			if (!@rename($tmp, $target)) {
				throw new Exception('Cannot rename ' . $tmp . ' to ' . $target . '.');
			}
			@chmod($target, 0660);
		} catch (Throwable $e) {
			@unlink($tmp);
			throw $e;
		}
	}

	/**
	 * Velikost a hash kopie proti zaznamu v DB. Starsi radky hash nemusi mit, pak se
	 * porovnava se zdrojem.
	 * @throws Exception
	 */
	protected function verify(string $copy, string $source, File $file): void
	{
		clearstatcache(true, $copy);

		$size = @filesize($copy);
		$expectedSize = @filesize($source);
		if ($size === false || $size !== $expectedSize) {
			throw new Exception('Size of ' . $copy . ' does not match the original.');
		}

		$hash = @md5_file($copy);
		$expectedHash = $file->getHash() ?? @md5_file($source);
		if ($hash === false || $hash !== $expectedHash) {
			throw new Exception('Hash of ' . $copy . ' does not match the original.');
		}
	}

	/**
	 * Smaze lokalni kopii uz archivovaneho souboru - jen kdyz archivni kopie opravdu sedi.
	 * @throws Exception
	 */
	protected function deleteLocalCopy(File $file, string $target): void
	{
		$local = $this->fileListener->getLocalDataDir($file) . '/' . $file->getFilename();

		if (!is_file($local)) {
			return;
		}

		$this->verify($target, $local, $file);
		@unlink($local);
	}

}
