<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Integration\Metadata;

use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\SqliteMetadataStore;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

final class SqliteMetadataStoreTest extends TestCase
{
    private string $databasePath;
    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'flysystem-telegram-');
        self::assertIsString($this->databasePath);
        unlink($this->databasePath);
        $this->store = new SqliteMetadataStore($this->databasePath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testWriteAndReadNonChunkedFile(): void
    {
        $file = new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, 'text/plain', Visibility::PRIVATE, 123, 'file-id', 'unique-id', '-100', 77);

        $this->store->write($file);
        $stored = $this->store->read('docs/a.txt');

        self::assertNotNull($stored);
        self::assertSame('docs/a.txt', $stored->metadata->path);
        self::assertSame(TelegramType::DOCUMENT, $stored->metadata->type);
        self::assertSame(5, $stored->metadata->size);
        self::assertSame('file-id', $stored->metadata->telegramFileId);
        self::assertSame([], $stored->chunks);
    }

    public function testReadMissingPathReturnsNull(): void
    {
        self::assertNull($this->store->read('missing.txt'));
    }

    public function testFileExistsReturnsExpectedBoolean(): void
    {
        self::assertFalse($this->store->fileExists('docs/a.txt'));

        $this->store->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, null, null, 123, 'file-id', null, '-100', 77));

        self::assertTrue($this->store->fileExists('docs/a.txt'));
    }

    public function testWriteChunkedFileAndReadChunksInOrder(): void
    {
        $file = new FileMetadata('big.bin', TelegramType::DOCUMENT, 9, null, Visibility::PRIVATE, 123, null, null, '-100', null, true, 4, 3);
        $chunks = [
            new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, '-100', 10),
            new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 4, 'file-1', null, '-100', 11),
            new ChunkMetadata('big.bin', 2, TelegramType::DOCUMENT, 1, 'file-2', null, '-100', 12),
        ];

        $this->store->write($file, $chunks);
        $stored = $this->store->read('big.bin');

        self::assertNotNull($stored);
        self::assertTrue($stored->isChunked());
        self::assertNull($stored->metadata->telegramFileId);
        self::assertCount(3, $stored->chunks);
        self::assertSame(['file-0', 'file-1', 'file-2'], array_map(static fn (ChunkMetadata $chunk): string => $chunk->telegramFileId, $stored->chunks));
    }

    public function testWriteReplacesExistingFileAndOldChunks(): void
    {
        $this->store->write(
            new FileMetadata('same.bin', TelegramType::DOCUMENT, 8, null, null, 100, null, null, '-100', null, true, 4, 2),
            [
                new ChunkMetadata('same.bin', 0, TelegramType::DOCUMENT, 4, 'old-0', null, '-100', 10),
                new ChunkMetadata('same.bin', 1, TelegramType::DOCUMENT, 4, 'old-1', null, '-100', 11),
            ],
        );

        $this->store->write(new FileMetadata('same.bin', TelegramType::DOCUMENT, 3, null, null, 200, 'new-file', null, '-100', 20));
        $stored = $this->store->read('same.bin');

        self::assertNotNull($stored);
        self::assertFalse($stored->isChunked());
        self::assertSame('new-file', $stored->metadata->telegramFileId);
        self::assertSame([], $stored->chunks);
    }

    public function testDeleteRemovesFileAndChunks(): void
    {
        $this->store->write(
            new FileMetadata('delete.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('delete.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->store->delete('delete.bin');

        self::assertNull($this->store->read('delete.bin'));
        self::assertFalse($this->store->fileExists('delete.bin'));
    }

    public function testDeleteMissingPathIsNoOp(): void
    {
        $this->store->delete('missing.txt');

        self::assertTrue(true);
    }

    public function testMoveUpdatesPathAndCascadesChunks(): void
    {
        $this->store->write(
            new FileMetadata('from/big.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('from/big.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->store->move('from/big.bin', 'to/big.bin');

        self::assertNull($this->store->read('from/big.bin'));
        $stored = $this->store->read('to/big.bin');
        self::assertNotNull($stored);
        self::assertSame('to/big.bin', $stored->chunks[0]->path);
    }

    public function testCopyDuplicatesMetadataAndReusesTelegramIds(): void
    {
        $this->store->write(
            new FileMetadata('source.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('source.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->store->copy('source.bin', 'copy.bin');

        $source = $this->store->read('source.bin');
        $copy = $this->store->read('copy.bin');

        self::assertNotNull($source);
        self::assertNotNull($copy);
        self::assertSame('source.bin', $source->metadata->path);
        self::assertSame('copy.bin', $copy->metadata->path);
        self::assertSame('chunk', $copy->chunks[0]->telegramFileId);
        self::assertGreaterThanOrEqual($source->metadata->lastModified, $copy->metadata->lastModified);
    }

    public function testSetVisibilityUpdatesExistingFile(): void
    {
        $this->store->write(new FileMetadata('visible.txt', TelegramType::DOCUMENT, 5, null, Visibility::PRIVATE, 100, 'file', null, '-100', 10));

        $this->store->setVisibility('visible.txt', Visibility::PUBLIC);

        self::assertSame(Visibility::PUBLIC, $this->store->read('visible.txt')?->metadata->visibility);
    }

    public function testListContentsShallowReturnsFilesAndVirtualDirectories(): void
    {
        $this->store->write(new FileMetadata('foo/a.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'a', null, '-100', 1));
        $this->store->write(new FileMetadata('foo/bar/b.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'b', null, '-100', 2));
        $this->store->write(new FileMetadata('foo/bar/c.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'c', null, '-100', 3));
        $this->store->write(new FileMetadata('foo/readme.md', TelegramType::DOCUMENT, 1, null, null, 100, 'r', null, '-100', 4));

        $items = iterator_to_array($this->store->listContents('foo', false));
        $paths = array_map(static fn (object $item): string => $item->path, $items);

        sort($paths);

        self::assertSame(['foo/a.txt', 'foo/bar', 'foo/readme.md'], $paths);
    }

    public function testListContentsDeepReturnsFilesAndVirtualDirectories(): void
    {
        $this->store->write(new FileMetadata('foo/a.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'a', null, '-100', 1));
        $this->store->write(new FileMetadata('foo/bar/b.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'b', null, '-100', 2));
        $this->store->write(new FileMetadata('foo/bar/baz/c.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'c', null, '-100', 3));

        $items = iterator_to_array($this->store->listContents('foo', true));
        $paths = array_map(static fn (object $item): string => $item->path, $items);

        sort($paths);

        self::assertSame(['foo/a.txt', 'foo/bar', 'foo/bar/b.txt', 'foo/bar/baz', 'foo/bar/baz/c.txt'], $paths);
    }
}
