<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use ADT\Files\ContentImages\ContentImageOwner;
use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Gedmo\Mapping\Annotation\SoftDeleteable;

/**
 * Owner deleted through Gedmo SoftDeleteable - the row stays with deletedAt set.
 */
#[Entity]
#[SoftDeleteable(fieldName: 'deletedAt')]
class TestSoftDeleteArticle implements ContentImageOwner
{
	use Identifier;

	#[Column(type: 'text', nullable: true)]
	protected ?string $content = null;

	#[Column(type: 'datetime', nullable: true)]
	protected ?DateTime $deletedAt = null;

	public function getContentImageFields(): array
	{
		return ['content'];
	}

	public function getContent(): ?string
	{
		return $this->content;
	}

	public function setContent(?string $content): static
	{
		$this->content = $content;
		return $this;
	}

	public function getDeletedAt(): ?DateTime
	{
		return $this->deletedAt;
	}
}
