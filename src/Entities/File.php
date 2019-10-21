<?php

namespace ADT\Files\Entities;

use ADT\Files\Helpers;
use Doctrine\ORM\Mapping as ORM;

/**
 * File
 *
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 */
class File {

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
	 * @ORM\Column(type="datetime", nullable=false)
	 */
	protected $createdAt;

	/**
	 * @var string
	 */
	protected $temporaryFile;

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

	public function setTemporaryFile(string $temporaryFile, string $originalName)
	{
		$this->temporaryFile = $temporaryFile;
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

		if (!rename($this->temporaryFile, $this->path  . '/' . $this->filename)) {
			throw new \Exception('File was not uploaded.');
		}

		$this->temporaryFile = null;
		$this->originalName = null;

		return $this;
	}
}
