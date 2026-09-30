<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use ADT\Files\Entities\File;
use ADT\Files\Entities\FileTrait;
use Doctrine\ORM\Mapping\Entity;

#[Entity]
class TestFile implements File
{
	use Identifier;
	use FileTrait;
}
