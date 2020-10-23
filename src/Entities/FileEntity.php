<?php
declare(strict_types=1);

namespace ADT\Files\Entities;

interface FileEntity
{

	public function getPath(): string;

	public function setBaseDirectoryPath(string $path): void;

	public function getUrl(): string;

	public function setBaseDirectoryUrl(string $url): void;

	public function setTemporaryContent(string $content, string $originalName): void;

	public function setTemporaryFile(string $filePath, string $originalName): void;

	public function hasTemporaryFile(): bool;

	public function saveFile(): void;

	public function setOnAfterSave(callable $callback): void;

	public function getOnAfterSave(): ?callable;

	public function getFilename(): string;

	public function getOriginalName(): string;

	public function getOnAfterDelete(): ?callable;

	public function setOnAfterDelete(callable $onAfterDelete): void;

}
