<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures;

use ADT\DoctrineComponents\EntityManager as AdtEntityManager;
use ADT\Files\Listeners\FileListener;
use ADT\Files\Storage\Storage;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gedmo\Translatable\Entity\Translation;

final class EntityManagerFactory
{
	/**
	 * The listener needs the entity manager and the entity manager needs the listener, so the
	 * listener is built first with the plain one and only then optionally wrapped in the ADT
	 * decorator - the decorator delegates to the same underlying unit of work either way.
	 *
	 * @param bool $adt whether to use ADT\DoctrineComponents\EntityManager (the one implementing
	 *                  TransactionCallbacksInterface) or plain Doctrine
	 * @param bool $nullableMimeType builds the schema with a nullable mime type, the way a
	 *                               database looks before the not null migration is run -
	 *                               the entity property stays a plain string either way
	 * @param string|Storage|null $archiveDir archive of the listener, null = archiving not configured
	 * @param string|Storage|null $privateArchiveDir archive of private files, null = $archiveDir
	 * @param string|null $dataUrl public URL of the data directory, null = files have no URL
	 * @param string|Storage|null $privateDataDir storage of private files, null = $dataDir
	 * @param Connection|null $connection database of another entity manager to share (its schema is kept), null = a fresh in-memory one
	 */
	public static function create(string|Storage $dataDir, bool $adt = true, bool $nullableMimeType = false, string|Storage|null $archiveDir = null, string|Storage|null $privateArchiveDir = null, ?string $dataUrl = null, string|Storage|null $privateDataDir = null, ?Connection $connection = null): EntityManagerInterface
	{
		// Gedmo translations are there for the content images tests
		$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Entity', dirname((string) new \ReflectionClass(Translation::class)->getFileName())], true);
		$config->enableNativeLazyObjects(true);

		$eventManager = new EventManager();
		$shared = $connection !== null;
		$connection ??= DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
		$plainEm = new EntityManager($connection, $config, $eventManager);

		$em = $adt ? new AdtEntityManager($plainEm) : $plainEm;

		$eventManager->addEventSubscriber(new FileListener($dataDir, $dataUrl, $privateDataDir ?? $dataDir, $em, $archiveDir, $privateArchiveDir));

		$allMetadata = $plainEm->getMetadataFactory()->getAllMetadata();

		if ($nullableMimeType) {
			foreach ($allMetadata as $metadata) {
				if (isset($metadata->fieldMappings['mimeType'])) {
					$metadata->fieldMappings['mimeType']->nullable = true;
				}
			}
		}

		if (!$shared) {
			new SchemaTool($plainEm)->createSchema($allMetadata);
		}

		return $em;
	}
}
