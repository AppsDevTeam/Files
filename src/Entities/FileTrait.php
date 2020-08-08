<?php

namespace ADT\Files\Entities;

use ADT\Files\Helpers;
use Doctrine\ORM\Mapping as ORM;

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
	 * @var \DateTime
	 *
	 * @ORM\Column(type="datetime_immutable")
	 */
	protected $createdAt;

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

	public function __construct()
	{
		$this->createdAt = new \DateTimeImmutable();
	}

	public function getPath()
	{
		return $this->path . '/' . $this->filename;
	}

	public function setPath(string $path)
	{
		$this->path = $path;

		return $this;
	}

	public function getUrl()
	{
		return $this->url . '/' . $this->filename;
	}

	public function setUrl(string $url)
	{
		$this->url = $url;

		return $this;
	}

	/**
	 * Creates a temporary file from string
	 *
	 * @param string $content
	 * @param string $originalName
	 */
	public function createTemporaryFile(string $content, string $originalName)
	{
		$temp = tmpfile();
		fwrite($temp, $content);

		$this->temporaryFile = $temp;
		$this->originalName = $originalName;

		return $this;
	}

	public function setTemporaryFile(string $temporaryFile, string $originalName)
	{
		if (!is_file($temporaryFile)) {
			throw new \Exception('Temporary file not found');
		}

		$this->temporaryFile = fopen($temporaryFile, 'r');
		$this->originalName = $originalName;

		return $this;
	}

	public function hasTemporaryFile()
	{
		return (bool) $this->temporaryFile;
	}

	public function saveFile()
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

		return $this;
	}
	
	public function setOnAfterSave($callback)
	{
		$this->onAfterSave = $callback;
		
		return $this;
	}

	public function getOnAfterSave()
	{
		return $this->onAfterSave;
	}

	/**
	 * @return string
	 */
	public function getFilename(): string
	{
		return $this->filename;
	}

	/**
	 * @return string
	 */
	public function getOriginalName(): string
	{
		return $this->originalName;
	}

	/**
	 * @return \DateTime
	 */
	public function getCreatedAt(): \DateTime
	{
		return $this->createdAt;
	}

	/**
	 * @return callable
	 */
	public function getOnAfterDelete(): callable
	{
		return $this->onAfterDelete;
	}

	/**
	 * @param callable $onAfterDelete
	 * @return self
	 */
	public function setOnAfterDelete(callable $onAfterDelete): self
	{
		$this->onAfterDelete = $onAfterDelete;
		return $this;
	}
}
