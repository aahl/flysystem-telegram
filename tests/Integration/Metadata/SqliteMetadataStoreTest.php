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
}
