<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Fake;

use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use RuntimeException;

final class FakeMetadataStore implements MetadataStore
{
    /** @var array<string, StoredFile> */
    public array $files = [];

    public bool $failWrites = false;

    /**
     * @param list<ChunkMetadata> $chunks
     */
    public function write(FileMetadata $file, array $chunks = []): void
    {
        if ($this->failWrites) {
            throw new RuntimeException('metadata write failed');
        }

        $this->files[$file->path] = new StoredFile($file, $chunks);
    }

    public function read(string $path): ?StoredFile
    {
        return $this->files[$path] ?? null;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function fileExists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        foreach ($this->files as $storedFile) {
            yield $storedFile->metadata;
        }
    }

    public function move(string $source, string $destination): void
    {
        $stored = $this->files[$source];
        unset($this->files[$source]);
        $metadata = $stored->metadata;
        $this->files[$destination] = new StoredFile(
            new FileMetadata($destination, $metadata->type, $metadata->size, $metadata->mimeType, $metadata->visibility, time(), $metadata->telegramFileId, $metadata->telegramFileUniqueId, $metadata->telegramChatId, $metadata->telegramMessageId, $metadata->isChunked, $metadata->chunkSize, $metadata->chunkCount),
            array_map(static fn (ChunkMetadata $chunk): ChunkMetadata => new ChunkMetadata($destination, $chunk->index, $chunk->type, $chunk->size, $chunk->telegramFileId, $chunk->telegramFileUniqueId, $chunk->telegramChatId, $chunk->telegramMessageId), $stored->chunks),
        );
    }

    public function copy(string $source, string $destination): void
    {
        $stored = $this->files[$source];
        $metadata = $stored->metadata;
        $this->files[$destination] = new StoredFile(
            new FileMetadata($destination, $metadata->type, $metadata->size, $metadata->mimeType, $metadata->visibility, time(), $metadata->telegramFileId, $metadata->telegramFileUniqueId, $metadata->telegramChatId, $metadata->telegramMessageId, $metadata->isChunked, $metadata->chunkSize, $metadata->chunkCount),
            array_map(static fn (ChunkMetadata $chunk): ChunkMetadata => new ChunkMetadata($destination, $chunk->index, $chunk->type, $chunk->size, $chunk->telegramFileId, $chunk->telegramFileUniqueId, $chunk->telegramChatId, $chunk->telegramMessageId), $stored->chunks),
        );
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $stored = $this->files[$path];
        $metadata = $stored->metadata;
        $this->files[$path] = new StoredFile(
            new FileMetadata($metadata->path, $metadata->type, $metadata->size, $metadata->mimeType, $visibility, $metadata->lastModified, $metadata->telegramFileId, $metadata->telegramFileUniqueId, $metadata->telegramChatId, $metadata->telegramMessageId, $metadata->isChunked, $metadata->chunkSize, $metadata->chunkCount),
            $stored->chunks,
        );
    }
}
