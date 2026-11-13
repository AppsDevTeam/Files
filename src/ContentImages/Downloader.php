<?php

declare(strict_types=1);

namespace ADT\Files\ContentImages;

interface Downloader
{
	/**
	 * Contents behind the URL, null when it cannot be downloaded or is bigger than $maxSize.
	 */
	public function download(string $url, int $maxSize): ?string;
}
