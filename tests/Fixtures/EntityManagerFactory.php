<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures;

use ADT\DoctrineComponents\EntityManager as AdtEntityManager;
use ADT\Files\Listeners\FileListener;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

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
	 */
	public static function create(string $dataDir, bool $adt = true, bool $nullableMimeType = false): EntityManagerInterface
	{
		$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Entity'], true);
		$config->enableNativeLazyObjects(true);

		$eventManager = new EventManager();
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
		$plainEm = new EntityManager($connection, $config, $eventManager);

		$em = $adt ? new AdtEntityManager($plainEm) : $plainEm;

		$eventManager->addEventSubscriber(new FileListener($dataDir, null, $dataDir, $em));

		$allMetadata = $plainEm->getMetadataFactory()->getAllMetadata();

		if ($nullableMimeType) {
			foreach ($allMetadata as $metadata) {
				if (isset($metadata->fieldMappings['mimeType'])) {
					$metadata->fieldMappings['mimeType']->nullable = true;
				}
			}
		}

		new SchemaTool($plainEm)->createSchema($allMetadata);

		return $em;
	}
}
