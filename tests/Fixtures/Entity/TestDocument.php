<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * Owner of a file, so that the orphan removal path can be tested - replacing the file
 * makes Doctrine remove the old entity without remove() ever being called explicitly.
 */
#[Entity]
class TestDocument
{
	use Identifier;

	#[OneToOne(targetEntity: TestFile::class, cascade: ['persist'], orphanRemoval: true)]
	#[JoinColumn(nullable: true)]
	protected ?TestFile $file = null;

	public function getFile(): ?TestFile
	{
		return $this->file;
	}

	public function setFile(?TestFile $file): static
	{
		$this->file = $file;
		return $this;
	}
}
