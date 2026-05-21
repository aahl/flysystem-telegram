<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Unit\Stream;

use Aahl\FlysystemTelegram\Exception\ChunkedStreamException;
use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Stream\ChunkedReadStream;
use Aahl\FlysystemTelegram\Stream\ChunkedReadStreamRegistry;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\Tests\Fake\FakeTelegramClient;
use PHPUnit\Framework\TestCase;

final class ChunkedReadStreamTest extends TestCase
{
    public function testChunkDownloadsAreLazy(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('flysystem-telegram-test-a', $client, $this->storedFile());

        self::assertIsResource($stream);
        self::assertSame([], $client->downloadedFileIds);
        self::assertSame('abc', fread($stream, 3));
        self::assertSame(['file-0'], $client->downloadedFileIds);
        self::assertSame('def', fread($stream, 3));
        self::assertSame(['file-0', 'file-1'], $client->downloadedFileIds);

        fclose($stream);
    }

    public function testStreamGetContentsConcatenatesChunks(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('flysystem-telegram-test-b', $client, $this->storedFile());

        self::assertSame('abcdef', stream_get_contents($stream));

        fclose($stream);
    }

    public function testCloseReleasesRegistryToken(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('flysystem-telegram-test-c', $client, $this->storedFile());
        $countBeforeClose = ChunkedReadStreamRegistry::count();

        fclose($stream);

        self::assertSame($countBeforeClose - 1, ChunkedReadStreamRegistry::count());
    }

    public function testOpenFailureReleasesRegistryToken(): void
    {
        $protocol = 'flysystem-telegram-open-fail-test';
        stream_wrapper_register($protocol, FailingOpenStreamWrapper::class);
        $registeredProtocols = new \ReflectionProperty(ChunkedReadStream::class, 'registeredProtocols');
        $registeredProtocols->setValue(null, [$protocol => true]);
        $countBeforeOpen = ChunkedReadStreamRegistry::count();

        try {
            $this->expectException(ChunkedStreamException::class);
            ChunkedReadStream::open($protocol, new FakeTelegramClient(), $this->storedFile());
        } finally {
            self::assertSame($countBeforeOpen, ChunkedReadStreamRegistry::count());
            $registeredProtocols->setValue(null, []);
            stream_wrapper_unregister($protocol);
        }
    }

    public function testDownloadFailureThrowsChunkedStreamException(): void
    {
        $this->expectException(ChunkedStreamException::class);

        $client = new FakeTelegramClient(['file-0' => 'abc'], 'file-0');
        $stream = ChunkedReadStream::open('flysystem-telegram-test-d', $client, $this->storedFile());

        fread($stream, 3);
    }

    public function testInvalidProtocolThrowsException(): void
    {
        $this->expectException(ChunkedStreamException::class);
        $this->expectExceptionMessage('Invalid stream protocol');

        ChunkedReadStream::open('1-invalid', new FakeTelegramClient(), $this->storedFile());
    }

    public function testCustomProtocolWorks(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('custom-telegram-fs-test', $client, $this->storedFile());

        self::assertSame('abcdef', stream_get_contents($stream));

        fclose($stream);
    }

    public function testExternalProtocolConflictThrowsException(): void
    {
        $protocol = 'flysystem-telegram-conflict-test';
        stream_wrapper_register($protocol, DummyConflictStreamWrapper::class);

        try {
            $this->expectException(ChunkedStreamException::class);
            $this->expectExceptionMessage('already registered');

            ChunkedReadStream::open($protocol, new FakeTelegramClient(), $this->storedFile());
        } finally {
            stream_wrapper_unregister($protocol);
        }
    }

    public function testChunkedStreamIsNotSeekable(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('flysystem-telegram-test-seek', $client, $this->storedFile());

        self::assertFalse(fseek($stream, 1) === 0);

        fclose($stream);
    }

    private function storedFile(): StoredFile
    {
        return new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 6, null, 'private', 100, null, null, '-100', null, true, 3, 2),
            [
                new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 3, 'file-0', null, '-100', 1),
                new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 3, 'file-1', null, '-100', 2),
            ],
        );
    }
}

final class DummyConflictStreamWrapper
{
    public mixed $context;
}

final class FailingOpenStreamWrapper
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}
