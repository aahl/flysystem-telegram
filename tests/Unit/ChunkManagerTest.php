<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Unit;

use Aahl\FlysystemTelegram\ChunkManager;
use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChunkManagerTest extends TestCase
{
    public function testShouldChunkUsesMaxFileSize(): void
    {
        $manager = new ChunkManager(100, 40);

        self::assertFalse($manager->shouldChunk(100));
        self::assertTrue($manager->shouldChunk(101));
    }

    public function testSplitStreamYieldsChunkStreamsWithSizes(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'abcdefghi');
        rewind($stream);

        $parts = iterator_to_array((new ChunkManager(5, 4))->splitStream($stream));

        self::assertCount(3, $parts);
        self::assertSame(0, $parts[0]->index);
        self::assertSame(4, $parts[0]->size);
        self::assertSame('abcd', stream_get_contents($parts[0]->stream));
        self::assertSame(1, $parts[1]->index);
        self::assertSame(4, $parts[1]->size);
        self::assertSame('efgh', stream_get_contents($parts[1]->stream));
        self::assertSame(2, $parts[2]->index);
        self::assertSame(1, $parts[2]->size);
        self::assertSame('i', stream_get_contents($parts[2]->stream));
    }

    public function testValidateStoredFileAcceptsConsistentChunks(): void
    {
        $file = new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 9, null, 'private', 100, null, null, 'chat', null, true, 4, 3),
            [
                new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, 'chat', 10),
                new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 4, 'file-1', null, 'chat', 11),
                new ChunkMetadata('big.bin', 2, TelegramType::DOCUMENT, 1, 'file-2', null, 'chat', 12),
            ],
        );

        (new ChunkManager(5, 4))->validateStoredFile($file);

        self::assertTrue(true);
    }

    public function testValidateStoredFileRejectsMissingChunk(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chunk count mismatch.');

        $file = new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 8, null, 'private', 100, null, null, 'chat', null, true, 4, 2),
            [new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, 'chat', 10)],
        );

        (new ChunkManager(5, 4))->validateStoredFile($file);
    }

    public function testValidateStoredFileRejectsNonContiguousIndexes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chunk index mismatch.');

        $file = new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 8, null, 'private', 100, null, null, 'chat', null, true, 4, 2),
            [
                new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, 'chat', 10),
                new ChunkMetadata('big.bin', 2, TelegramType::DOCUMENT, 4, 'file-2', null, 'chat', 12),
            ],
        );

        (new ChunkManager(5, 4))->validateStoredFile($file);
    }

    public function testValidateStoredFileRejectsSizeMismatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chunk size sum mismatch.');

        $file = new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 9, null, 'private', 100, null, null, 'chat', null, true, 4, 2),
            [
                new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, 'chat', 10),
                new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 4, 'file-1', null, 'chat', 11),
            ],
        );

        (new ChunkManager(5, 4))->validateStoredFile($file);
    }
}
