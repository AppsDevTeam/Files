<?php

declare(strict_types=1);

namespace ADT\Files\Entities;

use ADT\Files\BlockedExtensionException;
use ADT\Files\Helpers;
use ADT\Files\Storage\Storage;
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

	// not nullable - konzument tak nemusí řešit null tam, kde se čeká string (content type
	// response apod.). Default drží hodnotu do chvíle, než ji listener po uložení přepíše
	// tím, co opravdu zjistil z obsahu souboru.
	#[Column(nullable: false, options: ['default' => Helpers::DEFAULT_MIME_TYPE])]
	protected string $mimeType = Helpers::DEFAULT_MIME_TYPE;

	#[Column(nullable: false)]
	protected DateTimeImmutable $createdAt;

	#[Column(nullable: false, options: ['default' => 0])]
	protected bool $isPrivate = false;

	// Soubor lezi v archivu (FileListener $archiveDir), ne v datovem adresari. Nastavuje ho
	// jen Archiver po overenem presunu - zadny setter, priznak bez presunu by lhal o tom,
	// kde soubor je.
	#[Column(nullable: false, options: ['default' => 0])]
	protected bool $isArchived = false;

	protected ?string $temporaryFile = null;
	protected ?string $temporaryContent = null;
	protected ?string $stream = null;
	// uloziste, ve kterem soubor lezi (datove, nebo archiv) - nastavuje FileListener v
	// postLoad/postPersist, entita pres nej cte
	protected ?Storage $storage = null;
	/** @var callable */
	protected $onAfterSave;
	/** @var callable */
	protected $onAfterDelete;

	protected bool $ignoreMissingFile = false;

	public function __construct()
	{
		$this->createdAt = new DateTimeImmutable();
	}

	/**
	 * Cesta k souboru - na disku obycejna, v S3 URL stream wrapperu (s3://...), se kterou
	 * funkce PHP pro soubory pracuji stejne. Viz Storage::getPath().
	 * @throws Exception
	 */
	public function getPath(): string
	{
		$this->filename; // intentionally, because of lazy ghost objects https://github.com/doctrine/DoctrineBundle/issues/1651#issuecomment-1684297751
		return $this->getStorage()->getPath($this->filename);
	}

	/**
	 * @throws Exception
	 */
	public function getContents(): string
	{
		$this->filename; // intentionally, because of lazy ghost objects https://github.com/doctrine/DoctrineBundle/issues/1651#issuecomment-1684297751

		try {
			$stream = $this->getStorage()->read($this->filename);
		} catch (Exception $e) {
			if ($this->ignoreMissingFile) {
				return '';
			}
			throw $e;
		}

		try {
			return (string) stream_get_contents($stream);
		} finally {
			fclose($stream);
		}
	}

	public function setStorage(Storage $storage): self
	{
		$this->storage = $storage;
		return $this;
	}

	/**
	 * @throws Exception
	 */
	protected function getStorage(): Storage
	{
		if ($this->storage === null) {
			throw new Exception('Storage of the file is not set - is ' . \ADT\Files\Listeners\FileListener::class . ' registered?');
		}

		return $this->storage;
	}

	/**
	 * @throws Exception
	 */
	public function getUrl(): string
	{
		$this->filename; // intentionally, because of lazy ghost objects https://github.com/doctrine/DoctrineBundle/issues/1651#issuecomment-1684297751

		$url = $this->getStorage()->getUrl($this->filename);

		if ($url === null) {
			// archiv typicky neni pod document rootem - URL do datoveho adresare by vedla na
			// soubor, ktery uz tam neni
			if ($this->isArchived) {
				throw new Exception('Archived file has no public URL, serve it through the application.');
			}

			throw new Exception('Url is not set.');
		}

		return $url;
	}

	/**
	 * @throws Exception
	 * @throws BlockedExtensionException
	 */
	public function setTemporaryFile(string $temporaryFile, string $originalName): self
	{
		if (!is_file($temporaryFile)) {
			throw new Exception($temporaryFile . ' is not a file.');
		}

		$this->checkExtension($originalName);

		$this->temporaryFile = $temporaryFile;
		$this->originalName = $originalName;
		return $this;
	}

	public function getTemporaryFile(): ?string
	{
		return $this->temporaryFile;
	}

	/**
	 * @throws BlockedExtensionException
	 */
	public function setTemporaryContent(string $content, string $originalName): self
	{
		$this->checkExtension($originalName);

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

		$this->checkExtension($originalName);

		$this->stream = $stream;
		$this->originalName = $originalName;
		return $this;
	}

	/**
	 * @throws BlockedExtensionException
	 */
	protected function checkExtension(string $originalName): void
	{
		if (Helpers::isBlockedExtension($originalName)) {
			throw new BlockedExtensionException('Extension of file "' . $originalName . '" is not allowed.');
		}
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

	public function getMimeType(): string
	{
		$this->filename; // intentionally, because of lazy ghost objects https://github.com/doctrine/DoctrineBundle/issues/1651#issuecomment-1684297751

		return $this->mimeType;
	}

	public function setMimeType(string $mimeType): self
	{
		$this->mimeType = $mimeType;
		return $this;
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

	public function getCreatedAt(): DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getIsArchived(): bool
	{
		$this->filename; // intentionally, because of lazy ghost objects https://github.com/doctrine/DoctrineBundle/issues/1651#issuecomment-1684297751

		return $this->isArchived;
	}
}
