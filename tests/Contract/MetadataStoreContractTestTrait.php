<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Contract;

use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Assert;

trait MetadataStoreContractTestTrait
{
    abstract protected function metadataStore(): MetadataStore;

    public function testMetadataStoreWritesAndReadsNonChunkedFile(): void
    {
        $file = new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, 'text/plain', Visibility::PRIVATE, 123, 'file-id', 'unique-id', '-100', 77);

        $this->metadataStore()->write($file);
        $stored = $this->metadataStore()->read('docs/a.txt');

        Assert::assertNotNull($stored);
        Assert::assertSame('docs/a.txt', $stored->metadata->path);
        Assert::assertSame(TelegramType::DOCUMENT, $stored->metadata->type);
        Assert::assertSame(5, $stored->metadata->size);
        Assert::assertSame('text/plain', $stored->metadata->mimeType);
        Assert::assertSame(Visibility::PRIVATE, $stored->metadata->visibility);
        Assert::assertSame(123, $stored->metadata->lastModified);
        Assert::assertSame('file-id', $stored->metadata->telegramFileId);
        Assert::assertSame('unique-id', $stored->metadata->telegramFileUniqueId);
        Assert::assertSame('-100', $stored->metadata->telegramChatId);
        Assert::assertSame(77, $stored->metadata->telegramMessageId);
        Assert::assertFalse($stored->isChunked());
        Assert::assertSame([], $stored->chunks);
    }

    public function testMetadataStoreReturnsNullForMissingPath(): void
    {
        Assert::assertNull($this->metadataStore()->read('missing.txt'));
    }

    public function testMetadataStoreReportsFileExistence(): void
    {
        Assert::assertFalse($this->metadataStore()->fileExists('docs/a.txt'));

        $this->metadataStore()->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, null, null, 123, 'file-id', null, '-100', 77));

        Assert::assertTrue($this->metadataStore()->fileExists('docs/a.txt'));
    }

    public function testMetadataStoreWritesAndReadsChunkedFileInOrder(): void
    {
        $file = new FileMetadata('big.bin', TelegramType::DOCUMENT, 9, null, Visibility::PRIVATE, 123, null, null, '-100', null, true, 4, 3);
        $chunks = [
            new ChunkMetadata('big.bin', 2, TelegramType::DOCUMENT, 1, 'file-2', null, '-100', 12),
            new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, '-100', 10),
            new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 4, 'file-1', null, '-100', 11),
        ];

        $this->metadataStore()->write($file, $chunks);
        $stored = $this->metadataStore()->read('big.bin');

        Assert::assertNotNull($stored);
        Assert::assertTrue($stored->isChunked());
        Assert::assertNull($stored->metadata->telegramFileId);
        Assert::assertSame(4, $stored->metadata->chunkSize);
        Assert::assertSame(3, $stored->metadata->chunkCount);
        Assert::assertCount(3, $stored->chunks);
        Assert::assertSame([0, 1, 2], array_map(static fn (ChunkMetadata $chunk): int => $chunk->index, $stored->chunks));
        Assert::assertSame(['file-0', 'file-1', 'file-2'], array_map(static fn (ChunkMetadata $chunk): string => $chunk->telegramFileId, $stored->chunks));
    }

    public function testMetadataStoreWriteReplacesExistingFileAndOldChunks(): void
    {
        $this->metadataStore()->write(
            new FileMetadata('same.bin', TelegramType::DOCUMENT, 8, null, null, 100, null, null, '-100', null, true, 4, 2),
            [
                new ChunkMetadata('same.bin', 0, TelegramType::DOCUMENT, 4, 'old-0', null, '-100', 10),
                new ChunkMetadata('same.bin', 1, TelegramType::DOCUMENT, 4, 'old-1', null, '-100', 11),
            ],
        );

        $this->metadataStore()->write(new FileMetadata('same.bin', TelegramType::DOCUMENT, 3, null, null, 200, 'new-file', null, '-100', 20));
        $stored = $this->metadataStore()->read('same.bin');

        Assert::assertNotNull($stored);
        Assert::assertFalse($stored->isChunked());
        Assert::assertSame('new-file', $stored->metadata->telegramFileId);
        Assert::assertSame([], $stored->chunks);
    }

    public function testMetadataStoreDeleteRemovesFileAndChunks(): void
    {
        $this->metadataStore()->write(
            new FileMetadata('delete.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('delete.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->metadataStore()->delete('delete.bin');

        Assert::assertNull($this->metadataStore()->read('delete.bin'));
        Assert::assertFalse($this->metadataStore()->fileExists('delete.bin'));
    }

    public function testMetadataStoreDeleteMissingPathIsNoOp(): void
    {
        $this->metadataStore()->delete('missing.txt');

        Assert::assertFalse($this->metadataStore()->fileExists('missing.txt'));
    }

    public function testMetadataStoreMoveUpdatesPathAndKeepsChunksReadable(): void
    {
        $this->metadataStore()->write(
            new FileMetadata('from/big.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('from/big.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->metadataStore()->move('from/big.bin', 'to/big.bin');

        Assert::assertNull($this->metadataStore()->read('from/big.bin'));
        $stored = $this->metadataStore()->read('to/big.bin');
        Assert::assertNotNull($stored);
        Assert::assertSame('to/big.bin', $stored->metadata->path);
        Assert::assertSame('to/big.bin', $stored->chunks[0]->path);
        Assert::assertSame('chunk', $stored->chunks[0]->telegramFileId);
    }

    public function testMetadataStoreCopyDuplicatesMetadataAndChunks(): void
    {
        $this->metadataStore()->write(
            new FileMetadata('source.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('source.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->metadataStore()->copy('source.bin', 'copy.bin');

        $source = $this->metadataStore()->read('source.bin');
        $copy = $this->metadataStore()->read('copy.bin');

        Assert::assertNotNull($source);
        Assert::assertNotNull($copy);
        Assert::assertSame('source.bin', $source->metadata->path);
        Assert::assertSame('copy.bin', $copy->metadata->path);
        Assert::assertSame('chunk', $copy->chunks[0]->telegramFileId);
        Assert::assertSame('copy.bin', $copy->chunks[0]->path);
        Assert::assertGreaterThanOrEqual($source->metadata->lastModified, $copy->metadata->lastModified);
    }

    public function testMetadataStoreSetVisibilityUpdatesExistingFile(): void
    {
        $this->metadataStore()->write(new FileMetadata('visible.txt', TelegramType::DOCUMENT, 5, null, Visibility::PRIVATE, 100, 'file', null, '-100', 10));

        $this->metadataStore()->setVisibility('visible.txt', Visibility::PUBLIC);

        Assert::assertSame(Visibility::PUBLIC, $this->metadataStore()->read('visible.txt')?->metadata->visibility);
    }

    public function testMetadataStoreListContentsShallowReturnsFilesAndVirtualDirectories(): void
    {
        $this->metadataStore()->write(new FileMetadata('foo/a.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'a', null, '-100', 1));
        $this->metadataStore()->write(new FileMetadata('foo/bar/b.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'b', null, '-100', 2));
        $this->metadataStore()->write(new FileMetadata('foo/bar/c.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'c', null, '-100', 3));
        $this->metadataStore()->write(new FileMetadata('foo/readme.md', TelegramType::DOCUMENT, 1, null, null, 100, 'r', null, '-100', 4));

        $items = iterator_to_array($this->metadataStore()->listContents('foo', false));
        $paths = array_map(static fn (object $item): string => $item->path, $items);

        sort($paths);

        Assert::assertSame(['foo/a.txt', 'foo/bar', 'foo/readme.md'], $paths);
    }

    public function testMetadataStoreListContentsDeepReturnsFilesAndVirtualDirectories(): void
    {
        $this->metadataStore()->write(new FileMetadata('foo/a.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'a', null, '-100', 1));
        $this->metadataStore()->write(new FileMetadata('foo/bar/b.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'b', null, '-100', 2));
        $this->metadataStore()->write(new FileMetadata('foo/bar/baz/c.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'c', null, '-100', 3));

        $items = iterator_to_array($this->metadataStore()->listContents('foo', true));
        $paths = array_map(static fn (object $item): string => $item->path, $items);

        sort($paths);

        Assert::assertSame(['foo/a.txt', 'foo/bar', 'foo/bar/b.txt', 'foo/bar/baz', 'foo/bar/baz/c.txt'], $paths);
    }
}
