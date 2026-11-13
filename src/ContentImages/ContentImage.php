<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

use ADT\Files\Entities\File;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file that belongs to exactly one owner - the entity whose HTML shows it. The same picture in two entities
 * is two files, so editing one never breaks the other. The owner is polymorphic (class + id, no foreign key),
 * the same shape as Gedmo translations.
 *
 * The project maps the relation to its own File entity:
 *
 *     #[ORM\Entity]
 *     class ContentImage extends \ADT\Files\ContentImages\ContentImage
 *     {
 *         use Identifier;
 *
 *         #[ORM\ManyToOne(targetEntity: File::class)]
 *         #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
 *         protected File $file;
 *     }
 */
#[ORM\MappedSuperclass]
#[ORM\Index(name: 'content_image_owner', fields: ['ownerClass', 'ownerId'])]
abstract class ContentImage
{
	protected File $file;

	#[ORM\Column(type: 'string', length: 255)]
	protected string $ownerClass;

	// a string, so that a uuid owner fits as well as an integer one
	#[ORM\Column(type: 'string', length: 64)]
	protected string $ownerId;

	// the external URL the image was downloaded from, so that the same URL is not downloaded again
	#[ORM\Column(type: 'text', nullable: true)]
	protected ?string $sourceUrl = null;

	#[ORM\Column(type: 'datetime_immutable')]
	protected DateTimeImmutable $createdAt;

	public function __construct(File $file, string $ownerClass, string $ownerId)
	{
		$this->file = $file;
		$this->ownerClass = $ownerClass;
		$this->ownerId = $ownerId;
		$this->createdAt = new DateTimeImmutable();
	}

	public function getFile(): File
	{
		return $this->file;
	}

	public function getOwnerClass(): string
	{
		return $this->ownerClass;
	}

	public function getOwnerId(): string
	{
		return $this->ownerId;
	}

	public function getSourceUrl(): ?string
	{
		return $this->sourceUrl;
	}

	public function setSourceUrl(?string $sourceUrl): static
	{
		$this->sourceUrl = $sourceUrl;
		return $this;
	}

	public function getCreatedAt(): DateTimeImmutable
	{
		return $this->createdAt;
	}
}
