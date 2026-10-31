<?php

declare(strict_types=1);

namespace ADT\Files\Listeners;

use ADT\DoctrineComponents\TransactionCallbacksInterface;
use ADT\Files\Entities\File;
use ADT\Files\Helpers;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Exception\ORMException;
use Exception;
use Gedmo\SoftDeleteable\SoftDeleteable;

class FileListener implements EventSubscriber
{
	protected string $dataDir;
	protected ?string $dataUrl = null;
	protected ?string $privateDataDir = null;
	protected ?string $archiveDir = null;
	protected ?string $privateArchiveDir = null;
	protected EntityManagerInterface $em;
	/** @var File[] */
	protected array $filesToDelete = [];
	/** @var callable */
	protected $onAfterDelete;
	protected bool $ignoreMissingFiles = false;

	/**
	 * @throws Exception
	 */
	public function __construct(string $dataDir, ?string $dataUrl = null, ?string $privateDataDir = null, ?EntityManagerInterface $em = null, ?string $archiveDir = null, ?string $privateArchiveDir = null)
	{
		if (!$em) {
			throw new Exception("Class 'Doctrine\ORM\EntityManagerInterface' required by \$em in FileListener::__construct() not found.");
		}
		$this->em = $em;
		$this->dataDir = $dataDir;
		$this->dataUrl = $dataUrl;
		$this->privateDataDir = $privateDataDir;
		$this->archiveDir = $archiveDir;
		$this->privateArchiveDir = $privateArchiveDir;
	}

	public function getArchiveDir(): ?string
	{
		return $this->archiveDir;
	}

	/**
	 * Archivni adresar konkretniho souboru - soukrome soubory v $privateArchiveDir, pokud je
	 * nastaveny, aby archiv mohl kopirovat strukturu datovych adresaru.
	 */
	public function getArchiveDirFor(File $file): ?string
	{
		if ($file->getIsPrivate() && $this->privateArchiveDir !== null) {
			return $this->privateArchiveDir;
		}

		return $this->archiveDir;
	}

	/**
	 * Datovy adresar souboru bez ohledu na archivaci - kam patri, kdyz archivovany neni.
	 */
	public function getLocalDataDir(File $file): ?string
	{
		return $file->getIsPrivate() ? $this->privateDataDir : $this->dataDir;
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
		// radsi nechame lezet. Nepouzity soubor na disku se da uklidit, chybejici uz ne.
	}

	/**
	 * @param File[] $entities
	 */
	protected function deleteFiles(array $entities): void
	{
		foreach ($entities as $entity) {
			@unlink($entity->getPath());

			if ($this->onAfterDelete) {
				($this->onAfterDelete)($this, $entity);
			}
		}
	}

	/**
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

		$dataDir = $file->getIsPrivate() ? $this->privateDataDir : $this->dataDir;

		// we don't use file_exists because it's not an atomic operation
		// that's why we rather use @
		if (@mkdir(dirname($dataDir  . '/' . $filename), 0770, true)) {
			// we must use chmod because umask is applied to the permissions in mkdir command
			// default umask is mostly 0022, which executes 0770 & ~0022 and results in 0750
			chmod(dirname($dataDir  . '/' . $filename), 0770);
		}
		if ($file->getTemporaryFile()) {
			if (!rename($file->getTemporaryFile(), $dataDir  . '/' . $filename)) {
				throw new Exception('File was not uploaded.');
			}
		}
		elseif ($file->getStream()) {
			if (!copy($file->getStream(), $dataDir  . '/' . $filename)) {
				throw new Exception('File was not uploaded.');
			}
		}
		else {
			if (file_put_contents($dataDir  . '/' . $filename, $file->getTemporaryContent()) === false) {
				throw new Exception('File was not uploaded.');
			}
		}
		chmod($dataDir  . '/' . $filename, 0660);

		$filePath = $dataDir . '/' . $file->getFilename();

		// mime_content_type vrací false jen když soubor nejde přečíst - pro nerozpoznatelný
		// obsah vrátí application/octet-stream samo. Zavináč kvůli warningu v tom jednom případě.
		$mimeType = @mime_content_type($filePath) ?: Helpers::DEFAULT_MIME_TYPE;
		$file->setMimeType($mimeType);

		$this->em->createQuery('UPDATE ' . get_class($file) . ' e SET e.filename = :filename, e.size = :size, e.hash = :hash, e.mimeType = :mimeType WHERE e.id = :id')
			->setParameters([
				'id' => $file->getId(),
				'filename' => $file->getFilename(),
				'size' => filesize($filePath),
				// md5_file reads the file in chunks, md5($file->getContents()) would load
				// the whole file into memory and exhaust the memory limit on large files
				'hash' => md5_file($filePath) ?: null,
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
		// archivovany soubor lezi v archivu bez ohledu na to, jestli je soukromy - pod
		// document rootem neni ani jeden. Bez $archiveDir zustane datovy adresar a soubor
		// se proste nenajde: archiv musi byt nastaveny v kazdem procesu, ktery ho cte.
		if ($file->getIsArchived() && $this->archiveDir !== null) {
			$file->setBaseDirectoryPath($this->getArchiveDirFor($file));
		} else {
			$file->setBaseDirectoryPath($this->getLocalDataDir($file));
		}
		if ($this->dataUrl) {
			$file->setBaseDirectoryUrl($this->dataUrl);
		}
		if ($this->ignoreMissingFiles) {
			$file->ignoreMissingFile();
		}
	}
}
