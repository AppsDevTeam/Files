<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractTranslation;
use Gedmo\Translatable\Entity\Translation;

/**
 * Translations kept by Gedmo Translatable in ext_translations (or a custom AbstractTranslation entity).
 */
final class GedmoTranslations implements Translations
{
	/**
	 * @param class-string<AbstractTranslation> $translationClass
	 */
	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly string $translationClass = Translation::class,
	) {
	}

	public function find(ContentImageOwner $owner, string $ownerClass, array $fields): iterable
	{
		return $this->em->getRepository($this->translationClass)->findBy([
			'objectClass' => $ownerClass,
			'foreignKey' => (string) $owner->getId(),
			'field' => $fields,
		]);
	}

	public function getValue(object $translation): mixed
	{
		/** @var AbstractTranslation $translation */
		return $translation->getContent();
	}

	public function setValue(object $translation, mixed $value): void
	{
		/** @var AbstractTranslation $translation */
		$translation->setContent($value);
	}

	public function getOwnerOf(object $entity): ?array
	{
		if (!$entity instanceof $this->translationClass || !is_a($entity->getObjectClass(), ContentImageOwner::class, true)) {
			return null;
		}

		return [$entity->getObjectClass(), (string) $entity->getForeignKey()];
	}
}
