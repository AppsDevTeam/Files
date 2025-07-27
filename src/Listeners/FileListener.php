<?php

declare(strict_types=1);

namespace ADT\Files\Listeners;

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
	protected string $dataUrl;
	protected ?string $privateDataDir = null;
	protected EntityManagerInterface $em;
	/** @var File[] */
	protected array $filesToDelete = [];
	/** @var callable */
	protected $onAfterDelete;
	protected bool $ignoreMissingFiles = false;

	/**
	 * @throws Exception
	 */
	public function __construct(string $dataDir, ?string $dataUrl = null, ?string $privateDataDir = null, ?EntityManagerInterface $em = null)
	{
		if (!$em) {
			throw new Exception("Class 'Doctrine\ORM\EntityManagerInterface' required by \$em in FileListener::__construct() not found.");
		}
		$this->em = $em;
		$this->dataDir = $dataDir;
		$this->dataUrl = $dataUrl;
		$this->privateDataDir = $privateDataDir;
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
		foreach ($this->filesToDelete as $entity) {
			@unlink($entity->getPath());

			if ($this->onAfterDelete) {
				($this->onAfterDelete)($this, $entity);
			}
		}
		$this->filesToDelete = [];
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
			if (!rename($file->getTemporaryFile(), $this->dataDir  . '/' . $filename)) {
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

		$this->em->createQuery('UPDATE ' . get_class($file) . ' e SET e.filename = :filename, e.size = :size, e.hash = :hash WHERE e.id = :id')
			->setParameters([
				'id' => $file->getId(),
				'filename' => $file->getFilename(),
				'size' => filesize($dataDir . '/' . $file->getFilename()),
				'hash' => md5($file->getContents())
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
		$file->setBaseDirectoryPath($file->getIsPrivate() ? $this->privateDataDir : $this->dataDir);
		if ($this->dataUrl) {
			$file->setBaseDirectoryUrl($this->dataUrl);
		}
		if ($this->ignoreMissingFiles) {
			$file->ignoreMissingFile();
		}
	}
}
