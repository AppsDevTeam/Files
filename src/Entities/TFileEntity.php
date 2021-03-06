<?php

declare(strict_types=1);

namespace ADT\Files\Entities;

use ADT\Files\Helpers;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait TFileEntity
 * @package ADT\Files\Entities
 */
trait TFileEntity
{
	/**
	 * @var string
	 *
	 * @ORM\Column(type="string", nullable=false)
	 */
	protected $originalName;

	/**
	 * @var string
	 *
	 * @ORM\Column(type="string", nullable=true)
	 */
	protected $filename;

	/**
	 * @var string
	 */
	protected $temporaryFile;

	/**
	 * @var string
	 */
	protected $temporaryContent;

	/**
	 * @var string
	 */
	protected $path;

	/**
	 * @var string
	 */
	protected $url;

	/**
	 * @var callable
	 */
	protected $onAfterSave;

	/**
	 * @var callable
	 */
	protected $onAfterDelete;

	public function getPath(): string
	{
		return $this->path . '/' . $this->filename;
	}

	public function setBaseDirectoryPath(string $path): self
	{
		$this->path = $path;
		return $this;
	}

	public function getUrl(): string
	{
		return $this->url . '/' . $this->filename;
	}

	public function setBaseDirectoryUrl(string $url): self
	{
		$this->url = $url;
		return $this;
	}

	public function setTemporaryFile(string $temporaryFile, string $originalName): self
	{
		if (!is_file($temporaryFile)) {
			throw new \Exception('Temporary file not found');
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

	public function hasTemporaryFileOrContent(): bool
	{
		return $this->temporaryFile || $this->temporaryContent;
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
}
