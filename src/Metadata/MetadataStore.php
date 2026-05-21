<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

interface MetadataStore
{
    /**
     * @param list<ChunkMetadata> $chunks
     */
    public function write(FileMetadata $file, array $chunks = []): void;

    public function read(string $path): ?StoredFile;

    public function delete(string $path): void;

    public function fileExists(string $path): bool;

    /**
     * @return iterable<FileMetadata|DirectoryMetadata>
     */
    public function listContents(string $path, bool $deep): iterable;

    public function move(string $source, string $destination): void;

    public function copy(string $source, string $destination): void;

    public function setVisibility(string $path, string $visibility): void;
}
