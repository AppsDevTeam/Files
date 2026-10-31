<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures;

use ADT\Files\Entities\File;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Skryje vsechny radky file entit - zastupuje soft-delete filtr, ktery v aplikaci
 * schovava smazane radky, jejichz soubory ale na disku zustavaji.
 */
class HideAllFilesFilter extends SQLFilter
{
	public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
	{
		return $targetEntity->getReflectionClass()->implementsInterface(File::class) ? '1 = 0' : '';
	}
}
