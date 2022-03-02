<?php

declare(strict_types=1);

namespace ADT\Files\Entities;

interface IFileEntity
{
	public function getPath(): string;

	public function setBaseDirectoryPath(string $path): self;

	public function getUrl(): string;

	public function setBaseDirectoryUrl(string $url): self;

	public function setTemporaryFile(string $filePath, string $originalName): self;

	public function getTemporaryFile(): ?string;

	public function setTemporaryContent(string $content, string $originalName): self;

	public function getTemporaryContent(): ?string;

	public function setStream(string $stream, string $originalName): self;

	public function getStream(): ?string;

	public function isValid(): bool;

	public function setOnAfterSave(callable $callback): self;

	public function getOnAfterSave(): ?callable;

	public function setFilename(string $filename): self;

	public function getFilename(): string;
	
	public function getOriginalName(): string;

	public function getOnAfterDelete(): ?callable;
}
