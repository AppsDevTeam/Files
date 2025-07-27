<?php

declare(strict_types=1);

namespace ADT\Files\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;
use Exception;

trait FileTrait
{
	#[ORM\Column(type: 'string')]
	protected string $originalName;

	#[ORM\Column(type: 'string', nullable: true)]
	protected ?string $filename = null;

	#[ORM\Column(type: 'integer', nullable: true)]
	protected ?int $size = null;

	#[Column(nullable: true)]
	protected ?string $hash = null;

	#[Column(nullable: false)]
	protected DateTimeImmutable $createdAt;

	#[Column(nullable: false, options: ['default' => 0])]
	protected bool $isPrivate = false;

	protected ?string $temporaryFile = null;
	protected ?string $temporaryContent = null;
	protected ?string $stream = null;
	protected string $path;
	protected string $url;
	/** @var callable */
	protected $onAfterSave;
	/** @var callable */
	protected $onAfterDelete;

	protected bool $ignoreMissingFile = false;

	public function __construct()
	{
		$this->createdAt = new DateTimeImmutable();
	}

	public function getPath(): string
	{
		$this->filename; // intentionally, because of lazy ghost objects https://github.com/doctrine/DoctrineBundle/issues/1651#issuecomment-1684297751
		return $this->path . '/' . $this->filename;
	}

	public function getContents(): string
	{
		if ($this->ignoreMissingFile) {
			return (string) @file_get_contents($this->getPath());
		}

		return file_get_contents($this->getPath());
	}

	public function setBaseDirectoryPath(string $path): self
	{
		$this->path = $path;
		return $this;
	}

	/**
	 * @throws Exception
	 */
	public function getUrl(): string
	{
		$this->filename; // intentionally, because of lazy ghost objects https://github.com/doctrine/DoctrineBundle/issues/1651#issuecomment-1684297751

		if (!$this->url) {
			throw new Exception('Url is not set.');
		}

		return $this->url . '/' . $this->filename;
	}

	public function setBaseDirectoryUrl(string $url): self
	{
		$this->url = $url;
		return $this;
	}

	/**
	 * @throws Exception
	 */
	public function setTemporaryFile(string $temporaryFile, string $originalName): self
	{
		if (!is_file($temporaryFile)) {
			throw new Exception($temporaryFile . ' is not a file.');
		}

		$this->temporaryFile = $temporaryFile;
		$this->originalName = $originalName;
		return $this;
	}

	public function getTemporaryFile(): ?string
	{
		return $this->temporaryFile;
	}

	public function setTemporaryContent(string $content, string $originalName): self
	{
		$this->temporaryContent = $content;
		$this->originalName = $originalName;
		return $this;
	}

	public function getTemporaryContent(): ?string
	{
		return $this->temporaryContent;
	}

	/**
	 * @throws Exception
	 */
	public function setStream(string $stream, string $originalName): self
	{
		if (!@fopen($stream, 'r')) {
			throw new Exception($stream. ' is not a stream.');
		}

		$this->stream = $stream;
		$this->originalName = $originalName;
		return $this;
	}

	public function getStream(): ?string
	{
		return $this->stream;
	}

	public function isValid(): bool
	{
		return !is_null($this->temporaryFile) || !is_null($this->temporaryContent) || !is_null($this->stream);
	}

	public function setOnAfterSave(callable $callback): self
	{
		$this->onAfterSave = $callback;
		return $this;
	}

	public function getOnAfterSave(): ?callable
	{
		return $this->onAfterSave;
	}

	public function setFilename(string $filename): self
	{
		$this->filename = $filename;
		return $this;
	}

	public function getFilename(): string
	{
		return $this->filename;
	}

	public function getOriginalName(): string
	{
		return $this->originalName;
	}

	public function getOnAfterDelete(): ?callable
	{
		return $this->onAfterDelete;
	}

	public function getSize(): int
	{
		return $this->size;
	}

	public function getHash(): ?string
	{
		return $this->hash;
	}

	public function ignoreMissingFile(): void
	{
		$this->ignoreMissingFile = true;
	}

	public function getIsPrivate(): bool
	{
		return $this->isPrivate;
	}

	public function setIsPrivate(bool $isPrivate): self
	{
		$this->isPrivate = $isPrivate;
		return $this;
	}
}
