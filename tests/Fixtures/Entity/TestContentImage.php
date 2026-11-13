<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use ADT\Files\ContentImages\ContentImage;
use ADT\Files\Entities\File;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

#[Entity]
#[Index(name: 'content_image_owner', fields: ['ownerClass', 'ownerId'])]
class TestContentImage extends ContentImage
{
	use Identifier;

	#[ManyToOne(targetEntity: TestFile::class)]
	#[JoinColumn(nullable: false, onDelete: 'CASCADE')]
	protected File $file;
}
