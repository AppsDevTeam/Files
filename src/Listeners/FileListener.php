<?php
declare(strict_types=1);

namespace ADT\Files\Listeners;

use ADT\Files\Entities\IFileEntity;
use Doctrine\Common\EventManager;
use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use steevanb\DoctrineReadOnlyHydrator\Hydrator\ReadOnlyHydrator;

class FileListener implements EventSubscriber
{

	/**
	 * @var string
	 */
	protected $dataDir;

	/**
	 * @var string
	 */
	protected $dataUrl;

	/**
	 * @var EntityManagerInterface
	 */
	protected $em;

	/**
	 * @var IFileEntity[]
	 */
	protected $filesToDelete = [];

	/**
	 * FileListener constructor.
	 * @param string $dataDir
	 * @param string $dataUrl
	 * @param EntityManagerInterface $em
	 */
	public function __construct(string $dataDir, string $dataUrl, EntityManagerInterface $em)
	{
		$this->dataDir = $dataDir;
		$this->dataUrl = $dataUrl;
		$this->em = $em;
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

	/**
	 * @param LifecycleEventArgs $args
	 */
	public function postLoad(LifecycleEventArgs $args)
	{
		$entity = $args->getEntity();

		if (!$entity instanceof IFileEntity) {
			return;
		}

		$entity->setBaseDirectoryPath($this->dataDir);
		$entity->setBaseDirectoryUrl($this->dataUrl);
	}

	/**
	 * @param LifecycleEventArgs $args
	 * @throws \Exception
	 */
	public function postPersist(LifecycleEventArgs $args)
	{
		$entity = $args->getEntity();

		if (!$entity instanceof IFileEntity) {
			return;
		}

		if ($entity->hasTemporaryFile()) {
			$entity->setBaseDirectoryPath($this->dataDir);
			$entity->setBaseDirectoryUrl($this->dataUrl);
			$entity->saveFile();

			$this->em->flush();

			if ($entity->getOnAfterSave()) {
				call_user_func($entity->getOnAfterSave(), $entity);
			}
		}
	}

	public function preRemove(LifecycleEventArgs $eventArgs)
	{
		$entity = $eventArgs->getEntity();
		if (!$entity instanceof IFileEntity) {
			return;
		}

		// zabespeci nacteni entity pokud jde o proxy, v opacnem pripade
		// by v post flush nebylo mozne ziskat path lebo by doctrine nenasla entitu v db
		$entity->getPath();


		// smazane file entity si ulozime a pak je v post flush pouzijeme pro smazani jejich soboru
		// post remove nelze pouzit lebo sa ne vzdy zavola (treba pri smazani pres orphanremoval)
		// https://github.com/doctrine/orm/issues/6256
		$this->filesToDelete[] = $entity;
	}

	public function postFlush()
	{
		/** @var IFileEntity $entity */
		foreach ($this->filesToDelete as $entity) {
			@unlink($entity->getPath());

			if ($entity->getOnAfterDelete()) {
				call_user_func($entity->getOnAfterDelete(), $entity);
			}
		}
		$this->filesToDelete = [];
	}

}
