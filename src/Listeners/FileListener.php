<?php

namespace ADT\Files\Listeners;

use ADT\Files\Entities\FileTrait;
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
	 * @var \Kdyby\Doctrine\EntityManager
	 */
	protected $em;

	/**
	 * FileListener constructor.
	 * @param string $dataDir
	 * @param string $dataUrl
	 * @param \Kdyby\Events\EventManager $evm
	 * @param \Kdyby\Doctrine\EntityManager $em
	 */
	public function __construct(string $dataDir, string $dataUrl, \Kdyby\Events\EventManager $evm, \Kdyby\Doctrine\EntityManager $em)
	{
		$this->dataDir = $dataDir;
		$this->dataUrl = $dataUrl;
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
		];
	}

	/**
	 * @param LifecycleEventArgs $args
	 */
	public function postLoad(LifecycleEventArgs $args)
	{
		$entity = $args->getEntity();

		if (!$entity instanceof File) {
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

		if (!isset(class_uses($entity)[FileTrait::class])) {
			return;
		}

		if ($entity->hasTemporaryFile()) {
			$entity
				->setPath($this->dataDir)
				->setUrl($this->dataUrl)
				->saveFile();

			$this->em->flush($entity);
		}
	}
}
