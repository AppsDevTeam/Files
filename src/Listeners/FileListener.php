<?php

declare(strict_types=1);

namespace ADT\Files\Listeners;

use ADT\Files\Entities\IFileEntity;
use ADT\Files\Helpers;
use Doctrine\Common\EventManager;
use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Gedmo\SoftDeleteable\SoftDeleteable;
use steevanb\DoctrineReadOnlyHydrator\Hydrator\ReadOnlyHydrator;

class FileListener implements EventSubscriber
{
	protected string $dataDir;
	protected string $dataUrl;
	protected ?string $privateDataDir = null;
	protected EntityManagerInterface $em;
	/**
	 * @var IFileEntity[]
	 */
	protected array $filesToDelete = [];
	/**
	 * @var callable
	 */
	protected $onAfterDelete;
	protected bool $ignoreMissingFiles = false;

	public function __construct(string $dataDir, ?string $dataUrl, ?string $privateDataDir, EntityManagerInterface $em)
	{
		$this->em = $em;
		$this->dataDir = $dataDir;
		$this->dataUrl = $dataUrl;
		$this->privateDataDir = $privateDataDir;
	}

	public function ignoreMissingFiles(bool $ignoreMissingFiles)
	{
		$this->ignoreMissingFiles = $ignoreMissingFiles;
	}

	/**
	 * @return array|string[]
	 */
	public function getSubscribedEvents()
	{
		return [
			"postLoad",
			"postPersist",
			"preRemove",
			"postFlush",
		];
	}

	public function postLoad(PostLoadEventArgs $args)
	{
		$entity = $args->getObject();

		if (!$entity instanceof IFileEntity) {
			return;
		}

		$this->setupEntity($entity);
	}

	/**
	 * @throws \Exception
	 */
	public function postPersist(PostPersistEventArgs $args)
	{
		$entity = $args->getObject();

		if (!$entity instanceof IFileEntity) {
			return;
		}

		$this->setupEntity($entity);

		$this->saveFile($entity);
	}

	public function preRemove(PreRemoveEventArgs $eventArgs)
	{
		$entity = $eventArgs->getObject();

		if (!$entity instanceof IFileEntity) {
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

	/**
	 *
	 */
	public function postFlush()
	{
		/** @var IFileEntity $entity */
		foreach ($this->filesToDelete as $entity) {
			@unlink($entity->getPath());

			if ($this->onAfterDelete) {
				$this->onAfterDelete->call($this, $entity);
			}
		}
		$this->filesToDelete = [];
	}

	/**
	 * @throws \Exception
	 */
	protected function saveFile(IFileEntity $entity)
	{
		if (!$entity->isValid()) {
			throw new \Exception('Entity does not have a temporary file or content set.');
		}

		$filename = Helpers::getName($entity->getOriginalName(), $entity->getId());

		$entity->setFilename($filename);

		// we don't use file_exists because it's not an atomic operation
		// that's why we rather use @
		if (@mkdir(dirname($this->dataDir  . '/' . $filename), 0770, true)) {
			// we must use chmod because umask is applied to the permissions in mkdir command
			// default umask is mostly 0022, which executes 0770 & ~0022 and results in 0750
			chmod(dirname($this->dataDir  . '/' . $filename), 0770);
		}
		if ($entity->getTemporaryFile()) {
			if (!rename($entity->getTemporaryFile(), $this->dataDir  . '/' . $filename)) {
				throw new \Exception('File was not uploaded.');
			}
		}
		elseif ($entity->getStream()) {
			if (!copy($entity->getStream(), $this->dataDir  . '/' . $filename)) {
				throw new \Exception('File was not uploaded.');
			}
		}
		else {
			if (file_put_contents($this->dataDir  . '/' . $filename, $entity->getTemporaryContent()) === false) {
				throw new \Exception('File was not uploaded.');
			}
		}
		chmod($this->dataDir  . '/' . $filename, 0660);

		$this->em->createQuery('UPDATE ' . get_class($entity) . ' e SET e.filename = :filename, e.size = :size, e.hash = :hash WHERE e.id = :id')
			->setParameters([
				'id' => $entity->getId(),
				'filename' => $entity->getFilename(),
				'size' => filesize($this->dataDir . '/' . $entity->getFilename()),
				'hash' => md5($entity->getContents())
			])
			->execute();
		// because of low level update
		$this->em->refresh($entity);

		if ($entity->getOnAfterSave()) {
			call_user_func($entity->getOnAfterSave(), $entity);
		}
	}

	protected function setupEntity(IFileEntity $entity): void
	{
		$entity->setBaseDirectoryPath($entity->getIsPrivate() ? $this->privateDataDir : $this->dataDir);
		if ($this->dataUrl) {
			$entity->setBaseDirectoryUrl($this->dataUrl);
		}
		if ($this->ignoreMissingFiles) {
			$entity->ignoreMissingFile();
		}
	}
}
