<?php

declare(strict_types=1);

namespace ADT\Files;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Exception\PermanentErrorException;
use ADT\DoctrineComponents\TransactionCallbacksInterface;
use ADT\Files\Entities\File;
use ADT\Files\Listeners\FileListener;
use ADT\Files\Storage\LocalStorage;
use ADT\Files\Storage\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Throwable;

/**
 * Presun souboru z datoveho uloziste do archivu ($archiveDir ve FileListeneru - adresar na
 * NFS mountu, nebo S3 bucket). archive() jen zalozi background job, samotny presun dela
 * processArchive().
 *
 * Archiv muze byt chvili nedostupny (soft NFS mount vraci chyby misto zaseknuti, S3 odmitne
 * spojeni). Proto: zdroj se overi proti zaznamu v DB, Storage::put() rucí za to, ze kopie je
 * cela a shodna (fsync + hash na disku, Content-MD5 v S3), teprve pak priznak v DB a smazani
 * puvodni kopie (az po commitu). Jakakoli chyba = vyjimka = background queue job zopakuje
 * pozdeji; processArchive() je proto idempotentni.
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
		if ($this->fileListener->getArchiveStorage() === null) {
			throw new Exception('Archive is not configured, see FileListener $archiveDir.');
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
		if ($this->fileListener->getArchiveStorage() === null) {
			throw new PermanentErrorException('Archive is not configured, see FileListener $archiveDir.');
		}

		$file = $this->em->find($entityClass, $fileId);

		// mezitim smazany soubor uz neni co archivovat
		if (!$file) {
			return;
		}

		if (!$file instanceof File) {
			throw new PermanentErrorException($entityClass . ' does not implement ' . File::class . '.');
		}

		$name = $file->getFilename();
		$data = $this->fileListener->getDataStorageFor($file);
		$archive = $this->fileListener->getArchiveStorageFor($file);

		if ($file->getIsArchived()) {
			// Opakovany beh po padu mezi zapisem do DB a smazanim puvodni kopie - entita uz
			// ukazuje do archivu, puvodni kopii dohledame v datovem ulozisti.
			$this->deleteOriginal($file, $data, $archive);
			return;
		}

		if ($data->getSize($name) === null) {
			throw new PermanentErrorException('File ' . $data->getPath($name) . ' to archive does not exist.');
		}

		// put() chce lokalni soubor: z adresare se bere primo, z S3 se stahne do docasneho
		$downloaded = !$data instanceof LocalStorage;
		$source = $downloaded ? Helpers::downloadToTemporaryFile($data, $name) : $data->getPath($name);

		try {
			$this->verify($source, $file);
			$archive->put($name, $source, $file->getMimeType(), $file->getHash());
		} finally {
			if ($downloaded) {
				@unlink($source);
			}
		}

		$this->em->createQuery('UPDATE ' . $entityClass . ' e SET e.isArchived = true WHERE e.id = :id')
			->setParameter('id', $fileId)
			->execute();

		// stejne jako mazani ve FileListeneru: puvodni kopie jde pryc az po commitu, rollback
		// by jinak vratil radek ukazujici do datoveho uloziste, kde uz nic neni. Kdyz se smazat
		// nepovede (uloziste zrovna nedostupne), zustane lezet - radek uz rika "archivovano",
		// vyjimka po commitu by nikomu nepomohla; uklidi ji files:delete-orphans --archived
		// nebo dalsi beh tohoto jobu.
		$deleteOriginal = function () use ($data, $name): void {
			try {
				$data->delete($name);
			} catch (Throwable) {
			}
		};
		if ($this->em instanceof TransactionCallbacksInterface) {
			$this->em->afterCommit($deleteOriginal);
		} elseif (!$this->em->getConnection()->isTransactionActive()) {
			$deleteOriginal();
		}

		// low level update - entita v unit of work musi videt novy stav (a FileListener ji
		// v postLoad nastavi archiv)
		$this->em->refresh($file);
	}

	/**
	 * Zdroj proti zaznamu v DB - velikost a hash. Starsi radky hash nemusi mit, pak jen
	 * velikost. Archivovat kopii, ktera uz neodpovida tomu, co o ni vime, nema smysl.
	 * @throws Exception
	 */
	protected function verify(string $source, File $file): void
	{
		clearstatcache(true, $source);

		$size = @filesize($source);
		if ($size === false || $size !== $file->getSize()) {
			throw new Exception('Size of ' . $source . ' does not match the database.');
		}

		if ($file->getHash() !== null && @md5_file($source) !== $file->getHash()) {
			throw new Exception('Hash of ' . $source . ' does not match the database.');
		}
	}

	/**
	 * Smaze puvodni kopii uz archivovaneho souboru - jen kdyz archivni kopie opravdu existuje
	 * a je stejne velka.
	 * @throws Exception
	 */
	protected function deleteOriginal(File $file, Storage $data, Storage $archive): void
	{
		$name = $file->getFilename();
		$size = $data->getSize($name);

		if ($size === null) {
			return;
		}

		$archived = $archive->getSize($name);
		if ($archived === null || $archived !== $size) {
			throw new Exception('Archived copy of ' . $archive->getPath($name) . ' is missing or does not match, keeping the original.');
		}

		$data->delete($name);
	}
}
