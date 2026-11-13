<?php

declare(strict_types=1);

namespace ADT\Files\Tests\Fixtures\Entity;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use ADT\Files\ContentImages\ContentImageOwner;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Gedmo\Mapping\Annotation\Translatable;

/**
 * Owner whose HTML Gedmo Translatable keeps in the column (default locale) and in ext_translations (the others).
 */
#[Entity]
class TestTranslatableArticle implements ContentImageOwner
{
	use Identifier;

	#[Translatable]
	#[Column(type: 'text', nullable: true)]
	protected ?string $content = null;

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
}
