<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram;

use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\DirectoryMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\Metadata\SqliteMetadataStore;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Stream\ChunkedReadStream;
use Aahl\FlysystemTelegram\Telegram\GuzzleTelegramClient;
use Aahl\FlysystemTelegram\Telegram\TelegramClientInterface;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadedFile;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadRequest;
use Aahl\FlysystemTelegram\Upload\UploadStrategyResolver;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Throwable;

final class TelegramAdapter implements FilesystemAdapter
{
    private TelegramClientInterface $telegramClient;
    private MetadataStore $metadataStore;
    private ChunkManager $chunkManager;
    private UploadStrategyResolver $uploadStrategyResolver;

    public function __construct(
        private readonly TelegramAdapterConfig $config,
        ?TelegramClientInterface $telegramClient = null,
        ?MetadataStore $metadataStore = null,
        ?ChunkManager $chunkManager = null,
        ?UploadStrategyResolver $uploadStrategyResolver = null,
    ) {
        $this->telegramClient = $telegramClient ?? new GuzzleTelegramClient($config->resolveBotToken(), null, $config->apiBaseUri, $config->fileBaseUri, $config->timeout);
        $this->metadataStore = $metadataStore ?? new SqliteMetadataStore($config->resolveDatabasePath());
        $this->chunkManager = $chunkManager ?? new ChunkManager($config->maxFileSize, $config->chunkSize);
        $this->uploadStrategyResolver = $uploadStrategyResolver ?? new UploadStrategyResolver();
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->metadataStore->fileExists($path);
        } catch (Throwable $exception) {
            throw UnableToCheckFileExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            foreach ($this->metadataStore->listContents($path, true) as $item) {
                return true;
            }

            return false;
        } catch (Throwable $exception) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $exception);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw UnableToWriteFile::atLocation($path, 'Unable to create temporary stream.');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $size = $this->streamSize($contents);
            $mimeType = $this->uploadStrategyResolver->resolveMimeType($path, $config->get('mime_type'));
            $strategy = $this->uploadStrategyResolver->resolve($path, $mimeType, $size, $this->config);
            $visibility = $config->get('visibility') ?? $this->config->defaultVisibility;
            $chatId = $this->config->resolveChatId();
            $now = time();

            if ($strategy->chunked) {
                $this->writeChunkedDocument($path, $contents, $mimeType, $visibility, $chatId, $size, $strategy->chunkSize, $now);
                return;
            }

            try {
                $uploaded = $this->uploadWithFallback($path, $contents, $mimeType, $strategy->type, $chatId);
            } catch (Throwable $uploadException) {
                if (!$this->config->enableChunking) {
                    throw $uploadException;
                }

                $this->rewindStream($contents);

                $this->writeChunkedDocument($path, $contents, $mimeType, $visibility, $chatId, $size, $strategy->chunkSize, $now);
                return;
            }

            $file = new FileMetadata($path, $uploaded->type, $size, $mimeType, $visibility, $now, $uploaded->fileId, $uploaded->fileUniqueId, $uploaded->chatId, $uploaded->messageId);
            $this->metadataStore->write($file);
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            $contents = stream_get_contents($stream);

            if ($contents === false) {
                throw UnableToReadFile::fromLocation($path);
            }

            return $contents;
        } catch (UnableToReadFile $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $path)
    {
        try {
            $stored = $this->metadataStore->read($path);

            if ($stored === null) {
                throw UnableToReadFile::fromLocation($path);
            }

            if ($stored->isChunked()) {
                $this->chunkManager->validateStoredFile($stored);
                return ChunkedReadStream::open($this->config->chunkStreamProtocol, $this->telegramClient, $stored);
            }

            if ($stored->metadata->telegramFileId === null) {
                throw UnableToReadFile::fromLocation($path, 'Stored file does not have a Telegram file id.');
            }

            return $this->telegramClient->downloadStream($stored->metadata->telegramFileId);
        } catch (UnableToReadFile $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->metadataStore->delete($path);
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            foreach ($this->metadataStore->listContents($path, true) as $item) {
                if ($item instanceof FileMetadata) {
                    $this->metadataStore->delete($item->path);
                }
            }
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
    }

    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $this->metadataStore->setVisibility($path, $visibility);
        } catch (Throwable $exception) {
            throw UnableToSetVisibility::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $stored = $this->metadataOrFail($path, 'visibility');

        return new FileAttributes($path, null, $stored->metadata->visibility ?? $this->config->defaultVisibility);
    }

    public function mimeType(string $path): FileAttributes
    {
        $stored = $this->metadataOrFail($path, 'mime type');

        return new FileAttributes($path, null, null, null, $stored->metadata->mimeType);
    }

    public function lastModified(string $path): FileAttributes
    {
        $stored = $this->metadataOrFail($path, 'last modified');

        return new FileAttributes($path, null, null, $stored->metadata->lastModified);
    }

    public function fileSize(string $path): FileAttributes
    {
        $stored = $this->metadataOrFail($path, 'file size');

        return new FileAttributes($path, $stored->metadata->size);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            foreach ($this->metadataStore->listContents($path, $deep) as $item) {
                if ($item instanceof DirectoryMetadata) {
                    yield new DirectoryAttributes($item->path);
                    continue;
                }

                yield new FileAttributes($item->path, $item->size, $item->visibility, $item->lastModified, $item->mimeType);
            }
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->metadataStore->move($source, $destination);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->metadataStore->copy($source, $destination);
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    private function uploadWithFallback(string $path, mixed $contents, ?string $mimeType, string $type, string $chatId): TelegramUploadedFile
    {
        try {
            return $this->telegramClient->upload(new TelegramUploadRequest($type, $chatId, $contents, basename($path), $mimeType));
        } catch (Throwable $exception) {
            if ($type === TelegramType::DOCUMENT) {
                throw $exception;
            }

            $this->rewindStream($contents);

            return $this->telegramClient->upload(new TelegramUploadRequest(TelegramType::DOCUMENT, $chatId, $contents, basename($path), $mimeType));
        }
    }

    private function writeChunkedDocument(string $path, mixed $contents, ?string $mimeType, string $visibility, string $chatId, int $size, int $chunkSize, int $now): void
    {
        $chunks = [];
        $index = 0;

        foreach ($this->chunkManager->splitStream($contents, $chunkSize) as $part) {
            $uploaded = $this->telegramClient->upload(new TelegramUploadRequest(TelegramType::DOCUMENT, $chatId, $part->stream, basename($path) . '.part' . $index, 'application/octet-stream'));
            $chunks[] = new ChunkMetadata($path, $part->index, TelegramType::DOCUMENT, $part->size, $uploaded->fileId, $uploaded->fileUniqueId, $uploaded->chatId, $uploaded->messageId);
            $index++;
        }

        $file = new FileMetadata($path, TelegramType::DOCUMENT, $size, $mimeType, $visibility, $now, null, null, $chatId, null, true, $chunkSize, count($chunks));
        $this->metadataStore->write($file, $chunks);
    }

    private function metadataOrFail(string $path, string $metadataName): StoredFile
    {
        try {
            $stored = $this->metadataStore->read($path);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, $metadataName, $exception->getMessage(), $exception);
        }

        if ($stored === null) {
            throw UnableToRetrieveMetadata::create($path, $metadataName);
        }

        return $stored;
    }

    private function rewindStream(mixed $stream): void
    {
        if (!is_resource($stream) || !rewind($stream)) {
            throw new \RuntimeException('Unable to rewind stream for upload retry.');
        }
    }

    private function streamSize(mixed $stream): int
    {
        if (!is_resource($stream)) {
            throw new \RuntimeException('Expected stream resource.');
        }

        $stats = fstat($stream);

        if (is_array($stats)) {
            return (int) $stats['size'];
        }

        $position = ftell($stream);
        fseek($stream, 0, SEEK_END);
        $size = ftell($stream);
        fseek($stream, $position === false ? 0 : $position);

        if ($size === false) {
            throw new \RuntimeException('Unable to determine stream size.');
        }

        return $size;
    }
}
