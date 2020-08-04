<?php

namespace ADT\Files\Listeners;

use ADT\Files\Entities\FileTrait;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Event\LifecycleEventArgs;

class FileListener implements \Kdyby\Events\Subscriber
{
	use \Nette\SmartObject;

	/**
	 * @var string
	 */
	protected $dataDir;

	/**
	 * @var string
	 */
	protected $dataUrl;

	/**
	 * @var string
	 */
	protected $fileEntityClass;

	/**
	 * @var \Kdyby\Doctrine\EntityManager
	 */
	protected $em;

	protected $file;

	/**
	 * FileListener constructor.
	 * @param string $dataDir
	 * @param string $dataUrl
	 * @param \Kdyby\Events\EventManager $evm
	 * @param \Kdyby\Doctrine\EntityManager $em
	 */
	public function __construct(string $dataDir, string $dataUrl, string $fileEntityClass, \Kdyby\Events\EventManager $evm, \Kdyby\Doctrine\EntityManager $em)
	{
		$this->dataDir = $dataDir;
		$this->dataUrl = $dataUrl;
		$this->fileEntityClass = $fileEntityClass;
		$evm->addEventSubscriber($this);
		$this->em = $em;
	}

	/**
	 * @return array|string[]
	 */
	public function getSubscribedEvents() {
		return [
			"postLoad",
			"postPersist",
			"preRemove",
			"postRemove",
		];
	}

	/**
	 * @param LifecycleEventArgs $args
	 */
	public function postLoad(LifecycleEventArgs $args)
	{
		$entity = $args->getEntity();

		if (!$entity instanceof $this->fileEntityClass) {
			return;
		}

		$entity->setPath($this->dataDir)
			->setUrl($this->dataUrl);
	}

	/**
	 * @param LifecycleEventArgs $args
	 * @throws \Exception
	 */
	public function postPersist(LifecycleEventArgs $args)
	{
		$entity = $args->getEntity();

		if (!$entity instanceof $this->fileEntityClass) {
			return;
		}

		if ($entity->hasTemporaryFile()) {
			$entity
				->setPath($this->dataDir)
				->setUrl($this->dataUrl)
				->saveFile();

			$this->em->flush($entity);

			if ($entity->getOnAfterSave()) {
				call_user_func($entity->getOnAfterSave(), $entity);
			}
		}
	}

	public function preRemove(\Doctrine\ORM\Event\LifecycleEventArgs $eventArgs)
	{
		$entity = $eventArgs->getEntity();

		if (!$entity instanceof $this->fileEntityClass) {
			return;
		}

		$this->file = $this->em->createQueryBuilder()
			->select('e')
			->from($this->fileEntityClass, 'e')
			->where('e.id = :id')
			->setParameter('id', $entity->getId())
			->getQuery()
			->getResult('readOnly')[0];
	}

	public function postRemove(\Doctrine\ORM\Event\LifecycleEventArgs $eventArgs)
	{
		if (!$this->file) {
			return;
		}

		@unlink($this->file->getPath());

		if ($this->file->getOnAfterDelete()) {
			call_user_func($this->file->getOnAfterDelete(), $this->file);
		}

		$this->file = null;
	}
}
