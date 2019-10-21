<?php

namespace ADT\Files\Listeners;

use Adt\Files\Entities\File;
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
	 * FileListener constructor.
	 * @param string $dataDir
	 * @param string $dataUrl
	 * @param \Kdyby\Events\EventManager $evm
	 */
	public function __construct(string $dataDir, string $dataUrl, \Kdyby\Events\EventManager $evm) {
		$this->dataDir = $dataDir;
		$this->dataUrl = $dataUrl;
		$evm->addEventSubscriber($this);
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

		if (!$entity instanceof File) {
			return;
		}

		if ($entity->hasTemporaryFile()) {
			$entity
				->setPath($this->dataDir)
				->setUrl($this->dataUrl)
				->saveFile();
		}
	}
}

