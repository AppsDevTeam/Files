<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

use ADT\Files\Entities\File;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nette\Utils\Strings;
use Throwable;

/**
 * Turns the images in the HTML of an owner into files of that owner:
 * - a base64 data URI becomes a new file,
 * - a file of ours (under $dataUrl) the owner has stays, any other one is copied - content copied from one entity
 *   to another gets files of its own,
 * - an external image is downloaded,
 * - a blob URI and anything else is left alone.
 * Files of the owner no longer in its HTML are deleted.
 */
class ContentImageSynchronizer
{
	private string $dataPath;

	/** @var string[] */
	private array $ownHosts;

	private ContentImageOwner $owner;

	private string $ownerClass;

	/** @var array<int|string, ContentImage> file id => image of the owner */
	private array $owned = [];

	/** @var array<int|string, true> */
	private array $used = [];

	/** @var string[] */
	private array $warnings = [];

	/**
	 * @param class-string<File> $fileClass
	 * @param class-string<ContentImage> $contentImageClass
	 * @param string $dataUrl public URL of the data directory, the same as the FileListener's ('/data/files', or with a host)
	 * @param string|null $dataDir data directory - only needed to copy files under $dataUrl that have no File row
	 * @param string[] $ownHosts hosts (or URLs) whose absolute URLs are ours too - the domain of the site
	 */
	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly string $fileClass,
		private readonly string $contentImageClass,
		string $dataUrl,
		private readonly ?string $dataDir = null,
		array $ownHosts = [],
		private readonly Downloader $downloader = new StreamDownloader(),
		private readonly ?Translations $translations = null,
	) {
		$this->dataPath = trim((string) parse_url($dataUrl, PHP_URL_PATH), '/') . '/';
		$this->ownHosts = array_map(
			fn (string $host) => strtolower(parse_url($host, PHP_URL_HOST) ?? $host),
			array_filter([...$ownHosts, $dataUrl], fn (string $host) => !str_starts_with($host, '/')),
		);
	}

	/**
	 * @return string[] sources left as they were (a download failed, not an image, the file is missing)
	 * @throws Throwable
	 */
	public function sync(ContentImageOwner $owner): array
	{
		if ($owner->getId() === null) {
			throw new LogicException('Content images need a saved owner.');
		}

		$this->owner = $owner;
		$this->ownerClass = $this->getOwnerClass($owner);
		$this->owned = [];
		$this->used = [];
		$this->warnings = [];

		foreach ($this->findOwned($owner) as $contentImage) {
			$this->owned[$contentImage->getFile()->getId()] = $contentImage;
		}

		$this->em->beginTransaction();
		try {
			$metadata = $this->em->getClassMetadata($this->ownerClass);

			foreach ($owner->getContentImageFields() as $field) {
				$value = $metadata->getFieldValue($owner, $field);
				$processed = Html::map($value, $this->processHtml(...));

				if ($processed !== $value) {
					$metadata->setFieldValue($owner, $field, $processed);
				}
			}

			foreach ($this->findTranslations($owner) as $translation) {
				$value = $this->translations->getValue($translation);
				$processed = Html::map($value, $this->processHtml(...));

				if ($processed !== $value) {
					$this->translations->setValue($translation, $processed);
				}
			}

			foreach ($this->owned as $fileId => $contentImage) {
				if (!isset($this->used[$fileId])) {
					$this->em->remove($contentImage);
					$this->em->remove($contentImage->getFile());
				}
			}

			$this->em->flush();
			$this->em->commit();
		} catch (Throwable $e) {
			$this->em->rollback();
			throw $e;
		}

		return array_values(array_unique($this->warnings));
	}

	/**
	 * Number of images in the HTML of the owner by kind (Html::KIND_*), nothing is written.
	 *
	 * @return array<string, int>
	 */
	public function describe(ContentImageOwner $owner): array
	{
		$counts = [Html::KIND_DATA => 0, Html::KIND_BLOB => 0, Html::KIND_OWN => 0, Html::KIND_EXTERNAL => 0];

		foreach ($this->getHtmls($owner) as $html) {
			foreach (Html::getSources($html) as $src) {
				$kind = $this->getKind($src);
				if ($kind) {
					$counts[$kind]++;
				}
			}
		}

		return $counts;
	}

	/**
	 * @return ContentImage[]
	 */
	public function findOwned(ContentImageOwner $owner): array
	{
		return $this->em->getRepository($this->contentImageClass)->findBy([
			'ownerClass' => $this->getOwnerClass($owner),
			'ownerId' => (string) $owner->getId(),
		]);
	}

	/**
	 * Schedules the removal of every file of the owner, the caller flushes. For a deleted owner, which has
	 * no foreign key that would do it.
	 */
	public function removeAll(string $ownerClass, string $ownerId): void
	{
		$contentImages = $this->em->getRepository($this->contentImageClass)->findBy(['ownerClass' => $ownerClass, 'ownerId' => $ownerId]);

		foreach ($contentImages as $contentImage) {
			$this->em->remove($contentImage);
			$this->em->remove($contentImage->getFile());
		}
	}

	/**
	 * The real class behind a proxy - owners and their translations are stored under it.
	 */
	public function getOwnerClass(object $owner): string
	{
		return $this->em->getClassMetadata($owner::class)->getName();
	}

	public function getKind(string $src): ?string
	{
		$lower = strtolower($src);

		return match (true) {
			str_starts_with($lower, 'data:') => Html::KIND_DATA,
			str_starts_with($lower, 'blob:') => Html::KIND_BLOB,
			$this->getOwnPath($src) !== null => Html::KIND_OWN,
			(bool) preg_match('~^https?://~i', $src) => Html::KIND_EXTERNAL,
			default => null,
		};
	}

	/**
	 * @return string[]
	 */
	private function getHtmls(ContentImageOwner $owner): array
	{
		$htmls = [];
		$metadata = $this->em->getClassMetadata($this->getOwnerClass($owner));

		foreach ($owner->getContentImageFields() as $field) {
			array_push($htmls, ...Html::strings($metadata->getFieldValue($owner, $field)));
		}
		foreach ($this->findTranslations($owner) as $translation) {
			array_push($htmls, ...Html::strings($this->translations->getValue($translation)));
		}

		return $htmls;
	}

	/**
	 * @return iterable<object>
	 */
	private function findTranslations(ContentImageOwner $owner): iterable
	{
		return $this->translations?->find($owner, $this->getOwnerClass($owner), $owner->getContentImageFields()) ?? [];
	}

	private function processHtml(string $html): string
	{
		return (string) Html::replaceSources($html, $this->resolve(...));
	}

	/**
	 * New src of an image, null = keep it.
	 */
	private function resolve(string $src): ?string
	{
		switch ($this->getKind($src)) {
			case Html::KIND_DATA:
				$content = Html::decodeDataUri($src);
				$url = $content === null ? null : $this->store($content, 'image', null);
				if ($url === null) {
					$this->warnings[] = Strings::truncate($src, 40);
				}

				return $url;

			case Html::KIND_OWN:
				return $this->resolveOwn($src, (string) $this->getOwnPath($src));

			case Html::KIND_EXTERNAL:
				return $this->resolveExternal($src);

			default:
				return null;
		}
	}

	private function resolveOwn(string $src, string $relativePath): ?string
	{
		$file = $this->em->getRepository($this->fileClass)->findOneBy(['filename' => $relativePath]);

		if ($file && isset($this->owned[$file->getId()])) {
			$this->used[$file->getId()] = true;
			return $file->getUrl();
		}

		$content = null;
		if ($file) {
			$file->ignoreMissingFile();
			$content = $file->getContents();
		} elseif ($this->dataDir !== null && is_file($this->dataDir . '/' . $relativePath)) {
			$content = (string) file_get_contents($this->dataDir . '/' . $relativePath);
		}

		$url = $content ? $this->store($content, $file ? $file->getOriginalName() : basename($relativePath), null) : null;
		if ($url === null) {
			$this->warnings[] = $src;
		}

		return $url;
	}

	private function resolveExternal(string $src): ?string
	{
		// an import overwriting the HTML with the same external URLs again must not download them again
		foreach ($this->owned as $fileId => $contentImage) {
			if ($contentImage->getSourceUrl() === $src) {
				$this->used[$fileId] = true;
				return $contentImage->getFile()->getUrl();
			}
		}

		$content = $this->downloader->download($src, Html::MAX_SIZE);
		$url = $content === null ? null : $this->store($content, basename((string) parse_url($src, PHP_URL_PATH)), $src);
		if ($url === null) {
			$this->warnings[] = $src;
		}

		return $url;
	}

	private function store(string $content, string $name, ?string $sourceUrl): ?string
	{
		$extension = Html::getImageExtension($content);

		if ($extension === null || strlen($content) > Html::MAX_SIZE) {
			return null;
		}

		// the same picture twice in the HTML of one owner is one file
		$hash = md5($content);
		foreach ($this->owned as $fileId => $contentImage) {
			if ($contentImage->getFile()->getHash() === $hash) {
				$this->used[$fileId] = true;
				return $contentImage->getFile()->getUrl();
			}
		}

		$baseName = Strings::webalize(pathinfo($name, PATHINFO_FILENAME)) ?: 'image';

		/** @var File $file */
		$file = new $this->fileClass();
		$file->setTemporaryContent($content, $baseName . '.' . $extension);

		/** @var ContentImage $contentImage */
		$contentImage = new $this->contentImageClass($file, $this->ownerClass, (string) $this->owner->getId());
		$contentImage->setSourceUrl($sourceUrl);

		$this->em->persist($file);
		$this->em->persist($contentImage);
		// the URL of a file exists only once FileListener has saved it
		$this->em->flush();

		$this->owned[$file->getId()] = $contentImage;
		$this->used[$file->getId()] = true;

		return $file->getUrl();
	}

	/**
	 * Path inside the data directory: /data/files/…, the relative ../../data/files/… TinyMCE writes, or one of our hosts.
	 */
	private function getOwnPath(string $src): ?string
	{
		$parts = parse_url($src);

		if ($parts === false) {
			return null;
		}

		if (isset($parts['host'])) {
			if (!in_array(strtolower($parts['host']), $this->ownHosts, true)) {
				return null;
			}
		} elseif (isset($parts['scheme'])) {
			return null;
		}

		$path = ltrim((string) preg_replace('~^(\.\.?/)+~', '', $parts['path'] ?? ''), '/');

		if (!str_starts_with($path, $this->dataPath)) {
			return null;
		}

		$relativePath = rawurldecode(substr($path, strlen($this->dataPath)));

		return $relativePath === '' || str_contains($relativePath, '..') ? null : $relativePath;
	}
}
