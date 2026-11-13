<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

/**
 * Entity whose WYSIWYG HTML keeps its images as files of its own (ContentImage).
 */
interface ContentImageOwner
{
	/**
	 * Fields holding HTML - a string, or an array with HTML strings inside (a JSON column).
	 * Their translations are processed too when a Translations implementation is configured.
	 *
	 * @return string[]
	 */
	public function getContentImageFields(): array;

	public function getId();
}
