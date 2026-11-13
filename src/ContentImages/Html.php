<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

/**
 * Pure functions over the HTML of a WYSIWYG editor - no database, no files, usable on its own.
 */
final class Html
{
	public const string KIND_DATA = 'data';
	public const string KIND_BLOB = 'blob';
	public const string KIND_OWN = 'own';
	public const string KIND_EXTERNAL = 'external';

	/**
	 * Images a browser renders without running a script. SVG is left out on purpose - served from the data
	 * directory it is a document that can carry one.
	 */
	public const array IMAGE_EXTENSIONS = [
		'image/png' => 'png',
		'image/jpeg' => 'jpg',
		'image/gif' => 'gif',
		'image/webp' => 'webp',
	];

	public const int MAX_SIZE = 10 * 1024 * 1024;

	// the lookbehind keeps data-src and data-mce-src out
	private const string IMG_SRC_PATTERN = '~(<img\b[^>]*?(?<=\s)src\s*=\s*)(["\'])(.*?)\2~is';

	/**
	 * @return string[] decoded src of every <img>, in document order
	 */
	public static function getSources(?string $html): array
	{
		if ($html === null || stripos($html, '<img') === false) {
			return [];
		}

		preg_match_all(self::IMG_SRC_PATTERN, $html, $matches);

		return array_map(self::decodeAttribute(...), $matches[3]);
	}

	/**
	 * Rewrites the src of every <img>. The callback gets the decoded src and returns the new one,
	 * or null to keep it - the rest of the HTML is left byte for byte as it was.
	 *
	 * @param callable(string): ?string $callback
	 */
	public static function replaceSources(?string $html, callable $callback): ?string
	{
		if ($html === null || stripos($html, '<img') === false) {
			return $html;
		}

		return preg_replace_callback(self::IMG_SRC_PATTERN, static function (array $match) use ($callback): string {
			$src = $callback(self::decodeAttribute($match[3]));

			return $src === null ? $match[0] : $match[1] . $match[2] . htmlspecialchars($src, ENT_QUOTES) . $match[2];
		}, $html);
	}

	/**
	 * Applies a string transformation to a value of a field - a string, or every string inside an array
	 * (HTML stored in a JSON column). Other values come back untouched.
	 *
	 * @param callable(string): string $callback
	 */
	public static function map(mixed $value, callable $callback): mixed
	{
		if (is_string($value)) {
			return $callback($value);
		}

		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$value[$key] = self::map($item, $callback);
			}
		}

		return $value;
	}

	/**
	 * Every string of a field value, see map().
	 *
	 * @return string[]
	 */
	public static function strings(mixed $value): array
	{
		$strings = [];
		self::map($value, function (string $string) use (&$strings): string {
			$strings[] = $string;
			return $string;
		});

		return $strings;
	}

	/**
	 * Order (from 1) of the images that cannot be saved: a blob URI (it lives only in the browser tab
	 * it was created in) and a broken or unsupported data URI.
	 *
	 * @return int[]
	 */
	public static function findInvalidImages(?string $html): array
	{
		$invalid = [];

		foreach (self::getSources($html) as $i => $src) {
			$lower = strtolower($src);

			if (str_starts_with($lower, 'blob:') || str_starts_with($lower, 'data:') && !self::decodeDataUri($src)) {
				$invalid[] = $i + 1;
			}
		}

		return $invalid;
	}

	/**
	 * Contents of a base64 data URI holding one of IMAGE_EXTENSIONS, null otherwise. The type is detected
	 * from the bytes, the declared one is not trusted.
	 */
	public static function decodeDataUri(string $src): ?string
	{
		if (!preg_match('~^data:[a-z0-9.+/-]+;base64,(.*)$~is', $src, $match)) {
			return null;
		}

		$content = base64_decode((string) preg_replace('~\s+~', '', $match[1]), true);

		if ($content === false || strlen($content) > self::MAX_SIZE) {
			return null;
		}

		return self::getImageExtension($content) ? $content : null;
	}

	/**
	 * Extension for the contents of an allowed image, null for anything else.
	 */
	public static function getImageExtension(string $content): ?string
	{
		return self::IMAGE_EXTENSIONS[new \finfo(FILEINFO_MIME_TYPE)->buffer($content)] ?? null;
	}

	private static function decodeAttribute(string $value): string
	{
		return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}
}
