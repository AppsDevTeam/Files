<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Gedmo\Mapping\Annotation\SoftDeleteable;

/**
 * Owner of a file deleted through Gedmo SoftDeleteable - remove() cascades to the file
 * (orphanRemoval), then SoftDeleteable persists the owner again and Doctrine cancels the
 * removal of the file too, so its row stays.
 */
#[Entity]
#[SoftDeleteable(fieldName: 'deletedAt')]
class TestSoftDeleteDocument
{
	use Identifier;

	#[OneToOne(targetEntity: TestFile::class, cascade: ['persist'], orphanRemoval: true)]
	#[JoinColumn(nullable: true)]
	protected ?TestFile $file = null;

	#[Column(type: 'datetime', nullable: true)]
	protected ?DateTime $deletedAt = null;

	public function getFile(): ?TestFile
	{
		return $this->file;
	}

	public function setFile(?TestFile $file): static
	{
		$this->file = $file;
		return $this;
	}

	public function getDeletedAt(): ?DateTime
	{
		return $this->deletedAt;
	}
}
