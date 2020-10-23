<?php
declare(strict_types=1);

namespace ADT\Files\Entities;

use ADT\Files\Helpers;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait FileTrait
 * @package ADT\Files\Entities
 */
trait FileTrait
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

	public function setBaseDirectoryPath(string $path): void
	{
		$this->path = $path;
	}

	public function getUrl(): string
	{
		return $this->url . '/' . $this->filename;
	}

	public function setBaseDirectoryUrl(string $url): void
	{
		$this->url = $url;
	}

	public function setTemporaryContent(string $content, string $originalName): void
	{
		$temp = tmpfile();
		fwrite($temp, $content);

		$this->temporaryFile = $temp;
		$this->originalName = $originalName;
	}

	public function setTemporaryFile(string $temporaryFile, string $originalName): void
	{
		if (!is_file($temporaryFile)) {
			throw new \Exception('Temporary file not found');
		}

		$this->temporaryFile = fopen($temporaryFile, 'r');
		$this->originalName = $originalName;
	}

	public function hasTemporaryFile(): bool
	{
		return (bool) $this->temporaryFile;
	}

	public function saveFile(): void
	{
		if (empty($this->path)) {
			throw new \Exception('Use File::setPath() method before calling this method.');
		}

		$this->filename = Helpers::getName($this->originalName, $this->id);

		@mkdir(dirname($this->path  . '/' . $this->filename), 0775, true);
		if (!rename(stream_get_meta_data($this->temporaryFile)['uri'], $this->path  . '/' . $this->filename)) {
			throw new \Exception('File was not uploaded.');
		}

		fclose($this->temporaryFile);

		chmod($this->path  . '/' . $this->filename, 0664);

		$this->temporaryFile = null;
	}

	public function setOnAfterSave(callable $callback): void
	{
		$this->onAfterSave = $callback;
	}

	public function getOnAfterSave(): ?callable
	{
		return $this->onAfterSave;
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

	public function setOnAfterDelete(callable $onAfterDelete): void
	{
		$this->onAfterDelete = $onAfterDelete;
	}
}
