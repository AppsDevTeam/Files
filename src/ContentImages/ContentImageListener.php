<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

use ADT\DoctrineComponents\TransactionCallbacksInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Gedmo\Mapping\Annotation\SoftDeleteable as SoftDeleteableAttribute;
use LogicException;
use ReflectionClass;
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
	 * The synchronization runs after the owner has been committed, so a failure must not look like a failed save.
	 * With a handler the error goes here and the other owners are synchronized, without one it is thrown.
	 *
	 * @var array<callable(ContentImageOwner, Throwable): void>
	 */
	public array $onError = [];

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
			if ($entity instanceof ContentImageOwner && $entity->getId() !== null && !$this->isSoftDeleted($entity)) {
				$this->synchronizer->removeAll($this->synchronizer->getOwnerClass($entity), (string) $entity->getId());
			}
		}

		if ($this->paused || $this->syncing || $this->synchronizer->isSyncing()) {
			return;
		}

		foreach ($uow->getScheduledEntityInsertions() as $entity) {
			if ($entity instanceof ContentImageOwner) {
				$this->pending[spl_object_hash($entity)] = [$this->synchronizer->getOwnerClass($entity), $entity];
			}
			$this->addTranslationOwner($entity);
		}

		foreach ($uow->getScheduledEntityUpdates() as $entity) {
			if ($entity instanceof ContentImageOwner && $this->needsSync($entity, array_keys($uow->getEntityChangeSet($entity)))) {
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

	/**
	 * Its HTML changed, or it still holds a base64 image from before the listener - saving the entity converts it,
	 * even when the HTML itself was not touched. A data URI that cannot become a file (SVG, broken base64) does not
	 * count, it would make every save of the entity sync and warn again.
	 *
	 * @param string[] $changedFields
	 */
	private function needsSync(ContentImageOwner $owner, array $changedFields): bool
	{
		if (array_intersect($changedFields, $owner->getContentImageFields())) {
			return true;
		}

		$metadata = $this->em->getClassMetadata($this->synchronizer->getOwnerClass($owner));
		foreach ($owner->getContentImageFields() as $field) {
			foreach (Html::strings($metadata->getFieldValue($owner, $field)) as $html) {
				foreach (Html::getSources($html) as $src) {
					if (str_starts_with(strtolower($src), 'data:') && Html::decodeDataUri($src) !== null) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Gedmo SoftDeleteable turns the deletion into an update, the row stays and can be restored - so do its files.
	 * Mirrors its rule for a hard delete: hardDelete on and the owner already soft deleted (deletedAt in the past).
	 */
	private function isSoftDeleted(object $entity): bool
	{
		$ownerClass = $this->synchronizer->getOwnerClass($entity);
		$class = new ReflectionClass($ownerClass);

		do {
			foreach ($class->getAttributes(SoftDeleteableAttribute::class) as $attribute) {
				/** @var SoftDeleteableAttribute $config */
				$config = $attribute->newInstance();
				$deletedAt = $this->em->getClassMetadata($ownerClass)->getFieldValue($entity, $config->fieldName);

				return !$config->hardDelete || !$deletedAt instanceof DateTimeInterface || $deletedAt > new DateTimeImmutable();
			}
		} while ($class = $class->getParentClass());

		return false;
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

				try {
					$warnings = $this->synchronizer->sync($owner);
				} catch (Throwable $e) {
					if (!$this->onError) {
						throw $e;
					}
					foreach ($this->onError as $callback) {
						$callback($owner, $e);
					}
					continue;
				}

				foreach ($warnings as $src) {
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
