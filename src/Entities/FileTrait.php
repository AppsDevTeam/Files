<?php

namespace ADT\Files\Entities;

use ADT\Files\Helpers;
use Doctrine\ORM\Mapping as ORM;


trait FileTrait {

	use \Kdyby\Doctrine\Entities\Attributes\Identifier;

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
	public $temporaryFile;

	/**
	 * @var string
	 */
	protected $originalName;

	/**
	 * @var string
	 */
	protected $path;

	/**
	 * @var string
	 */
	protected $url;

	public function __construct()
	{
		$this->createdAt = new \DateTime();
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

		if (!rename(stream_get_meta_data($this->temporaryFile)['uri'], $this->path  . '/' . $this->filename)) {
			throw new \Exception('File was not uploaded.');
		}

		fclose($this->temporaryFile);

		$this->temporaryFile = null;
		$this->originalName = null;

		return $this;
	}
}
