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
	#[ORM\Column(type: 'string')]
	protected $originalName;

	/**
	 * @var string
	 *
	 * @ORM\Column(type="string", nullable=true)
	 */
	#[ORM\Column(type: 'string', nullable: true)]
	protected $filename;

	/**
	 * @var int
	 *
	 * @ORM\Column(type="integer", nullable=true)
	 */
	#[ORM\Column(type: 'integer', nullable: true)]
	protected ?int $size = null;

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
	protected $stream;

	/**
	 * @var string
	 */
	protected $filepath;

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

	/**
	 * @var callable
	 */
	protected $fileNameCallback;

	public function getFilepath(): string
	{

		return $this->path . '/' . $this->getFilename();
	}

	public function setBaseDirectoryPath(string $path): self
	{
		$this->path = $path;
		return $this;
	}

	public function getUrl(): string
	{

		return $this->url . '/' . $this->getFilename();
	}

	public function setBaseDirectoryUrl(string $url): self
	{
		$this->url = $url;
		return $this;
	}

	public function setTemporaryFile(string $temporaryFile, string $originalName): self
	{
		if (!is_file($temporaryFile)) {
			throw new \Exception($temporaryFile . ' is not a file.');
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

	public function setStream(string $stream, string $originalName): self
	{
		if (!@fopen($stream, 'r')) {
			throw new \Exception($stream. ' is not a stream.');
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
		return $this->temporaryFile || $this->temporaryContent || $this->stream;
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
		if(isset($this->fileNameCallback)) {
			$this->fileNameCallback($this->path);
		}
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

	public function setSize(int $size): self
	{
		$this->size = $size;
		return $this;
	}

	public function getFileNameCallback(): ?callable {
		return $this->fileNameCallback;
	}


	public function setFileNameCallback(?callable $fileNameCallback): self {
		$this->fileNameCallback = $fileNameCallback;
		return $this;
	}

}
