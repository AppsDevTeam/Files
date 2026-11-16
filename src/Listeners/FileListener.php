<?php

declare(strict_types=1);

namespace ADT\Files\Listeners;

use ADT\DoctrineComponents\TransactionCallbacksInterface;
use ADT\Files\Entities\File;
use ADT\Files\Helpers;
use ADT\Files\Storage\LocalStorage;
use ADT\Files\Storage\Storage;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Exception\ORMException;
use Exception;
use Gedmo\SoftDeleteable\SoftDeleteable;
use Throwable;

class FileListener implements EventSubscriber
{
	protected Storage $dataStorage;
	protected ?Storage $privateDataStorage = null;
	protected ?Storage $archiveStorage = null;
	protected ?Storage $privateArchiveStorage = null;
	protected EntityManagerInterface $em;
	/** @var File[] */
	protected array $filesToDelete = [];
	/** @var callable */
	protected $onAfterDelete;
	protected bool $ignoreMissingFiles = false;

	/**
	 * Kazde uloziste je bud Storage (S3Storage, LocalStorage), nebo cesta k adresari, ktera
	 * se zabali do LocalStorage - puvodni konfigurace projektu tak funguje beze zmeny.
	 * $dataUrl plati jen pro adresare: Storage si svou URL nese sama.
	 * @throws Exception
	 */
	public function __construct(string|Storage $dataDir, ?string $dataUrl = null, string|Storage|null $privateDataDir = null, ?EntityManagerInterface $em = null, string|Storage|null $archiveDir = null, string|Storage|null $privateArchiveDir = null)
	{
		if (!$em) {
			throw new Exception("Class 'Doctrine\ORM\EntityManagerInterface' required by \$em in FileListener::__construct() not found.");
		}
		$this->em = $em;
		$this->dataStorage = self::storage($dataDir, $dataUrl);
		// privatni adresar dostaval stejnou URL odjakziva - zustava, at se projektum nic nemeni
		$this->privateDataStorage = self::storage($privateDataDir, $dataUrl);
		$this->archiveStorage = self::storage($archiveDir);
		$this->privateArchiveStorage = self::storage($privateArchiveDir);
	}

	private static function storage(string|Storage|null $storage, ?string $url = null): ?Storage
	{
		if ($storage === null || $storage instanceof Storage) {
			return $storage;
		}

		return new LocalStorage($storage, $url);
	}

	public function getDataStorage(): Storage
	{
		return $this->dataStorage;
	}

	public function getArchiveStorage(): ?Storage
	{
		return $this->archiveStorage;
	}

	/**
	 * Archiv konkretniho souboru - soukrome soubory v $privateArchiveDir, pokud je nastaveny,
	 * aby archiv mohl kopirovat strukturu datovych ulozist.
	 */
	public function getArchiveStorageFor(File $file): ?Storage
	{
		if ($file->getIsPrivate() && $this->privateArchiveStorage !== null) {
			return $this->privateArchiveStorage;
		}

		return $this->archiveStorage;
	}

	/**
	 * Datove uloziste souboru bez ohledu na archivaci - kam patri, kdyz archivovany neni.
	 */
	public function getDataStorageFor(File $file): Storage
	{
		return $file->getIsPrivate() ? ($this->privateDataStorage ?? $this->dataStorage) : $this->dataStorage;
	}

	/**
	 * Uloziste, ve kterem soubor prave lezi. Archivovany soubor lezi v archivu bez ohledu
	 * na to, jestli je soukromy. Bez archivu v konfiguraci zustane datove uloziste a soubor
	 * se proste nenajde: archiv musi byt nastaveny v kazdem procesu, ktery ho cte.
	 */
	public function getStorageFor(File $file): Storage
	{
		if ($file->getIsArchived() && ($archive = $this->getArchiveStorageFor($file)) !== null) {
			return $archive;
		}

		return $this->getDataStorageFor($file);
	}

	public function ignoreMissingFiles(bool $ignoreMissingFiles): static
	{
		$this->ignoreMissingFiles = $ignoreMissingFiles;
		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getSubscribedEvents(): array
	{
		return [
			Events::postLoad,
			Events::postPersist,
			Events::preRemove,
			Events::postFlush,
		];
	}

	public function postLoad(PostLoadEventArgs $args): void
	{
		$entity = $args->getObject();

		if (!$entity instanceof File) {
			return;
		}

		$this->setupEntity($entity);
	}

	/**
	 * @throws Exception|ORMException
	 */
	public function postPersist(PostPersistEventArgs $args): void
	{
		$entity = $args->getObject();

		if (!$entity instanceof File) {
			return;
		}

		$this->setupEntity($entity);

		$this->saveFile($entity);
	}

	public function preRemove(PreRemoveEventArgs $eventArgs): void
	{
		$entity = $eventArgs->getObject();

		if (!$entity instanceof File) {
			return;
		}

		if ($entity instanceof SoftDeleteable) {
			return;
		}

		// zabezpeci nacteni entity pokud jde o proxy, v opacnem pripade
		// by v post flush nebylo mozne ziskat path lebo by doctrine nenasla entitu v db
		$entity->getPath();

		// smazane file entity si ulozime a pak je v post flush pouzijeme pro smazani jejich souboru
		// post remove nelze pouzit lebo sa ne vzdy zavola (treba pri smazani pres orphanremoval)
		// https://github.com/doctrine/orm/issues/6256
		$this->filesToDelete[] = $entity;
	}

	public function postFlush(): void
	{
		if (!$this->filesToDelete) {
			return;
		}

		$entities = $this->filesToDelete;
		$this->filesToDelete = [];

		// postFlush bezi jeste uvnitr transakce, takze smazat soubor tady je nebezpecne:
		// pripadny rollback vrati entitu do db, ale soubor uz je nenavratne pryc
		if ($this->em instanceof TransactionCallbacksInterface) {
			$this->em->afterCommit(fn () => $this->deleteFiles($entities));
			return;
		}

		if (!$this->em->getConnection()->isTransactionActive()) {
			$this->deleteFiles($entities);
			return;
		}

		// Bez TransactionCallbacksInterface nemame jak se dozvedet o commitu, takze soubor
		// radsi nechame lezet. Nepouzity soubor v ulozisti se da uklidit, chybejici uz ne.
	}

	/**
	 * @param File[] $entities
	 */
	protected function deleteFiles(array $entities): void
	{
		foreach ($entities as $entity) {
			// radek uz je pryc - soubor, ktery nejde smazat (archiv zrovna nedostupny), zustane
			// jako sirotek, to je lepsi nez vyjimka po commitu
			try {
				$this->getStorageFor($entity)->delete($entity->getFilename());
			} catch (Throwable) {
			}

			if ($this->onAfterDelete) {
				($this->onAfterDelete)($this, $entity);
			}
		}
	}

	/**
	 * Obsah entity (docasny soubor, stream nebo retezec) se nejdriv dostane do lokalniho
	 * souboru, z nej se zjisti mime type, velikost a hash, a teprve pak jde do uloziste -
	 * to muze byt S3, kde by se tohle zjistovalo mnohem hur.
	 * @throws Exception
	 * @throws ORMException
	 */
	protected function saveFile(File $file): void
	{
		if (!$file->isValid()) {
			throw new Exception('Entity does not have a temporary file or content set.');
		}

		$filename = Helpers::getName($file->getOriginalName(), $file->getId());

		$file->setFilename($filename);

		$storage = $this->getDataStorageFor($file);
		$file->setStorage($storage);

		// docasny soubor od aplikace se do uloziste presouva (po ulozeni je pryc, jako driv
		// pres rename); obsah a stream jdou pres vlastni docasny soubor
		$ownTemporaryFile = !$file->getTemporaryFile();
		if (!$ownTemporaryFile) {
			$source = $file->getTemporaryFile();
		} else {
			$source = tempnam(sys_get_temp_dir(), 'adt-files-');
			if ($source === false) {
				throw new Exception('Cannot create a temporary file.');
			}
			$written = $file->getStream()
				? @copy($file->getStream(), $source)
				: @file_put_contents($source, $file->getTemporaryContent()) !== false;
			if (!$written) {
				@unlink($source);
				throw new Exception('File was not uploaded.');
			}
		}

		try {
			// mime_content_type vrací false jen když soubor nejde přečíst - pro nerozpoznatelný
			// obsah vrátí application/octet-stream samo. Zavináč kvůli warningu v tom jednom případě.
			$mimeType = @mime_content_type($source) ?: Helpers::DEFAULT_MIME_TYPE;
			$size = filesize($source);
			// md5_file reads the file in chunks, md5($file->getContents()) would load
			// the whole file into memory and exhaust the memory limit on large files
			$hash = md5_file($source) ?: null;

			$storage->put($filename, $source, $mimeType);
		} catch (Throwable $e) {
			if ($ownTemporaryFile) {
				@unlink($source);
			}
			throw $e;
		}
		@unlink($source);

		$file->setMimeType($mimeType);

		$this->em->createQuery('UPDATE ' . get_class($file) . ' e SET e.filename = :filename, e.size = :size, e.hash = :hash, e.mimeType = :mimeType WHERE e.id = :id')
			->setParameters([
				'id' => $file->getId(),
				'filename' => $file->getFilename(),
				'size' => $size,
				'hash' => $hash,
				'mimeType' => $mimeType,
			])
			->execute();
		// because of low level update
		$this->em->refresh($file);

		if ($file->getOnAfterSave()) {
			call_user_func($file->getOnAfterSave(), $file);
		}
	}

	protected function setupEntity(File $file): void
	{
		$file->setStorage($this->getStorageFor($file));
		if ($this->ignoreMissingFiles) {
			$file->ignoreMissingFile();
		}
	}
}
