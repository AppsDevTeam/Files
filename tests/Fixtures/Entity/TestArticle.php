<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use ADT\Files\ContentImages\ContentImageOwner;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * Owner of content images: HTML in a text column and HTML inside a JSON column.
 */
#[Entity]
class TestArticle implements ContentImageOwner
{
	use Identifier;

	#[Column(type: 'text', nullable: true)]
	protected ?string $content = null;

	#[Column(type: 'json', nullable: true)]
	protected ?array $faq = null;

	#[Column(type: 'string', nullable: true)]
	protected ?string $title = null;

	public function getContentImageFields(): array
	{
		return ['content', 'faq'];
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

	public function getFaq(): ?array
	{
		return $this->faq;
	}

	public function setFaq(?array $faq): static
	{
		$this->faq = $faq;
		return $this;
	}

	public function setTitle(?string $title): static
	{
		$this->title = $title;
		return $this;
	}
}
