<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Unit;

use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\TelegramAdapter;
use Aahl\FlysystemTelegram\Tests\Fake\FakeMetadataStore;
use Aahl\FlysystemTelegram\Tests\Fake\FakeTelegramClient;
use League\Flysystem\Config;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

final class TelegramAdapterTest extends TestCase
{
    public function testWriteStoresUploadedMetadata(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient();
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), $telegramClient, $metadataStore);

        $adapter->write('docs/a.txt', 'hello', new Config(['mime_type' => 'text/plain', 'visibility' => Visibility::PRIVATE]));

        $stored = $metadataStore->read('docs/a.txt');
        self::assertNotNull($stored);
        self::assertSame(TelegramType::DOCUMENT, $stored->metadata->type);
        self::assertSame(5, $stored->metadata->size);
        self::assertSame(Visibility::PRIVATE, $stored->metadata->visibility);
        self::assertSame('uploaded-file-id-1', $stored->metadata->telegramFileId);
    }

    public function testMetadataWriteFailureDoesNotTriggerChunkedFallback(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->failWrites = true;
        $telegramClient = new FakeTelegramClient();
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), $telegramClient, $metadataStore);

        try {
            $adapter->write('docs/a.txt', 'hello', new Config(['mime_type' => 'text/plain']));
            self::fail('Expected write to fail.');
        } catch (\League\Flysystem\UnableToWriteFile) {
            self::assertSame([TelegramType::DOCUMENT], $telegramClient->uploadedTypes);
        }
    }

    public function testReadDownloadsStoredFile(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, 'text/plain', Visibility::PRIVATE, 100, 'file-id', null, '-100', 1));
        $telegramClient = new FakeTelegramClient(['file-id' => 'hello']);
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), $telegramClient, $metadataStore);

        self::assertSame('hello', $adapter->read('docs/a.txt'));
    }

    public function testTypedUploadFailureFallsBackToDocument(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient(uploadFailures: [TelegramType::PHOTO]);
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), $telegramClient, $metadataStore);

        $adapter->write('photo.jpg', 'hello', new Config(['mime_type' => 'image/jpeg']));

        self::assertSame([TelegramType::PHOTO, TelegramType::DOCUMENT], $telegramClient->uploadedTypes);
        self::assertSame(TelegramType::DOCUMENT, $metadataStore->read('photo.jpg')?->metadata->type);
    }

    public function testDocumentUploadFailureFallsBackToChunkedDocumentWhenEnabled(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient(uploadFailures: [TelegramType::PHOTO, TelegramType::DOCUMENT]);
        $adapter = new TelegramAdapter(
            new TelegramAdapterConfig(
                botToken: 'token',
                chatId: '-100',
                chunkSize: 3,
                typeSizeLimits: [TelegramType::PHOTO => 100, TelegramType::DOCUMENT => 100],
            ),
            $telegramClient,
            $metadataStore,
        );

        $adapter->write('photo.jpg', 'hello', new Config(['mime_type' => 'image/jpeg']));

        self::assertSame([TelegramType::PHOTO, TelegramType::DOCUMENT, TelegramType::DOCUMENT, TelegramType::DOCUMENT], $telegramClient->uploadedTypes);
        $stored = $metadataStore->read('photo.jpg');
        self::assertNotNull($stored);
        self::assertTrue($stored->isChunked());
        self::assertSame(2, $stored->metadata->chunkCount);
    }

    public function testDocumentUploadFailureWithChunkingDisabledThrowsWriteException(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient(uploadFailures: [TelegramType::DOCUMENT]);
        $adapter = new TelegramAdapter(
            new TelegramAdapterConfig(
                botToken: 'token',
                chatId: '-100',
                enableChunking: false,
            ),
            $telegramClient,
            $metadataStore,
        );

        $this->expectException(\League\Flysystem\UnableToWriteFile::class);

        $adapter->write('archive.zip', 'hello', new Config(['mime_type' => 'application/zip']));
    }

    public function testChunkedWriteUsesEffectiveChunkSizeFromUploadStrategy(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient();
        $adapter = new TelegramAdapter(
            new TelegramAdapterConfig(
                botToken: 'token',
                chatId: '-100',
                chunkSize: 5000,
                typeSizeLimits: [TelegramType::DOCUMENT => 3],
            ),
            $telegramClient,
            $metadataStore,
        );

        $adapter->write('big.bin', 'abcdefg', new Config());

        $stored = $metadataStore->read('big.bin');
        self::assertNotNull($stored);
        self::assertTrue($stored->isChunked());
        self::assertSame(3, $stored->metadata->chunkSize);
        self::assertSame(3, $stored->metadata->chunkCount);
    }

    public function testReadMissingFileThrowsFlysystemException(): void
    {
        $this->expectException(UnableToReadFile::class);

        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), new FakeMetadataStore());

        $adapter->read('missing.txt');
    }

    public function testFileExistsWrapsMetadataFailure(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->failFileExists = true;
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), $metadataStore);

        $this->expectException(UnableToCheckFileExistence::class);

        $adapter->fileExists('docs/a.txt');
    }

    public function testDirectoryExistsWrapsMetadataFailure(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->failListContents = true;
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), $metadataStore);

        $this->expectException(UnableToCheckDirectoryExistence::class);

        $adapter->directoryExists('docs');
    }

    public function testListContentsWrapsMetadataFailure(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->failListContents = true;
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), $metadataStore);

        $this->expectException(UnableToListContents::class);

        iterator_to_array($adapter->listContents('docs', true));
    }

    public function testDeleteDirectoryWrapsMetadataFailure(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, null, null, 100, 'file-id', null, '-100', 1));
        $metadataStore->failDeletes = true;
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), $metadataStore);

        $this->expectException(UnableToDeleteDirectory::class);

        $adapter->deleteDirectory('docs');
    }

    public function testDeleteRemovesMetadataOnly(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, null, null, 100, 'file-id', null, '-100', 1));
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(['file-id' => 'hello']), $metadataStore);

        $adapter->delete('docs/a.txt');

        self::assertFalse($metadataStore->fileExists('docs/a.txt'));
    }

    public function testMoveCopyAndVisibilityDelegateToMetadataStore(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->write(new FileMetadata('source.txt', TelegramType::DOCUMENT, 5, null, Visibility::PRIVATE, 100, 'file-id', null, '-100', 1));
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), $metadataStore);

        $adapter->copy('source.txt', 'copy.txt', new Config());
        $adapter->move('copy.txt', 'moved.txt', new Config());
        $adapter->setVisibility('moved.txt', Visibility::PUBLIC);

        self::assertTrue($metadataStore->fileExists('source.txt'));
        self::assertTrue($metadataStore->fileExists('moved.txt'));
        self::assertSame(Visibility::PUBLIC, $metadataStore->read('moved.txt')?->metadata->visibility);
    }

    public function testMetadataMethodsReturnFlysystemAttributes(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, 'text/plain', Visibility::PRIVATE, 123, 'file-id', null, '-100', 1));
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), $metadataStore);

        self::assertTrue($adapter->fileExists('docs/a.txt'));
        self::assertSame(5, $adapter->fileSize('docs/a.txt')->fileSize());
        self::assertSame('text/plain', $adapter->mimeType('docs/a.txt')->mimeType());
        self::assertSame(123, $adapter->lastModified('docs/a.txt')->lastModified());
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('docs/a.txt')->visibility());
    }
}
