<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

/**
 * Translated values of the HTML fields of an owner, which live outside the entity itself.
 */
interface Translations
{
	/**
	 * @param string[] $fields
	 * @return iterable<object> managed entities whose value is read and written by getValue() / setValue()
	 */
	public function find(ContentImageOwner $owner, string $ownerClass, array $fields): iterable;

	public function getValue(object $translation): mixed;

	public function setValue(object $translation, mixed $value): void;

	/**
	 * Owner class and id of a changed translation entity, null when it is not a translation of an HTML field.
	 *
	 * @return array{string, string}|null
	 */
	public function getOwnerOf(object $entity): ?array;
}
