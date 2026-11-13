<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

use ADT\DoctrineComponents\TransactionCallbacksInterface;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use LogicException;
use Throwable;

/**
 * Keeps the images of every ContentImageOwner in sync without the project calling anything: onFlush notes
 * the owners whose HTML changed, and once the outermost transaction commits the synchronizer runs on them.
 * It cannot run inside the flush - a new file gets its URL only after it is saved, a new owner its id only
 * after the insert, and Doctrine 3 dispatches postFlush before it clears the unit of work, so flushing there
 * would write everything twice. A deleted owner takes its files along in the same flush.
 */
class ContentImageListener implements EventSubscriber
{
	/** @var array<string, array{string, object}> */
	private array $pending = [];

	/** @var array<string, array{string, string}> */
	private array $pendingIds = [];

	private bool $scheduled = false;

	private bool $syncing = false;

	private bool $paused = false;

	/** @var array<callable(ContentImageOwner, string): void> sources left as they were, see ContentImageSynchronizer::sync() */
	public array $onWarning = [];

	/**
	 * @param EntityManagerInterface $em the decorated one implementing TransactionCallbacksInterface - Doctrine hands the inner one to the events
	 */
	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly ContentImageSynchronizer $synchronizer,
		private readonly ?Translations $translations = null,
	) {
	}

	public function getSubscribedEvents(): array
	{
		return [
			Events::onFlush,
		];
	}

	/**
	 * Bulk operations that rewrite HTML on their own (an import) can switch the synchronization off.
	 */
	public function pause(): void
	{
		$this->paused = true;
	}

	public function resume(): void
	{
		$this->paused = false;
	}

	public function onFlush(OnFlushEventArgs $args): void
	{
		$uow = $args->getObjectManager()->getUnitOfWork();

		foreach ($uow->getScheduledEntityDeletions() as $entity) {
			if ($entity instanceof ContentImageOwner && $entity->getId() !== null) {
				$this->synchronizer->removeAll($this->synchronizer->getOwnerClass($entity), (string) $entity->getId());
			}
		}

		if ($this->paused || $this->syncing) {
			return;
		}

		foreach ($uow->getScheduledEntityInsertions() as $entity) {
			if ($entity instanceof ContentImageOwner) {
				$this->pending[spl_object_hash($entity)] = [$this->synchronizer->getOwnerClass($entity), $entity];
			}
			$this->addTranslationOwner($entity);
		}

		foreach ($uow->getScheduledEntityUpdates() as $entity) {
			if ($entity instanceof ContentImageOwner && array_intersect(array_keys($uow->getEntityChangeSet($entity)), $entity->getContentImageFields())) {
				$this->pending[spl_object_hash($entity)] = [$this->synchronizer->getOwnerClass($entity), $entity];
			}
			$this->addTranslationOwner($entity);
		}

		if (($this->pending || $this->pendingIds) && !$this->scheduled) {
			if (!$this->em instanceof TransactionCallbacksInterface) {
				throw new LogicException(self::class . ' needs an EntityManager implementing ' . TransactionCallbacksInterface::class . '.');
			}

			$this->scheduled = true;
			$this->em->afterCommit($this->syncPending(...));
			$this->em->afterRollback($this->clear(...));
		}
	}

	private function addTranslationOwner(object $entity): void
	{
		$owner = $this->translations?->getOwnerOf($entity);
		if ($owner) {
			$this->pendingIds[$owner[0] . '#' . $owner[1]] = $owner;
		}
	}

	/**
	 * @throws Throwable
	 */
	private function syncPending(): void
	{
		$owners = [];
		foreach ($this->pending as [$class, $entity]) {
			if ($entity->getId() !== null) {
				$owners[$class . '#' . $entity->getId()] = $this->em->contains($entity) ? $entity : $this->em->find($class, $entity->getId());
			}
		}
		foreach ($this->pendingIds as $key => [$class, $id]) {
			$owners[$key] ??= $this->em->find($class, $id);
		}
		$this->clear();

		$this->syncing = true;
		try {
			foreach ($owners as $owner) {
				if (!$owner instanceof ContentImageOwner) {
					continue;
				}

				foreach ($this->synchronizer->sync($owner) as $src) {
					foreach ($this->onWarning as $callback) {
						$callback($owner, $src);
					}
				}
			}
		} finally {
			$this->syncing = false;
		}
	}

	private function clear(): void
	{
		$this->pending = $this->pendingIds = [];
		$this->scheduled = false;
	}
}
