<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Fake;

use Aahl\FlysystemTelegram\Telegram\TelegramClientInterface;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadedFile;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadRequest;
use RuntimeException;

final class FakeTelegramClient implements TelegramClientInterface
{
    /**
     * @param array<string, string> $downloads
     * @param list<string> $uploadFailures
     */
    public function __construct(
        public array $downloads = [],
        public ?string $failDownloadFor = null,
        public array $uploadFailures = [],
    ) {
    }

    /** @var list<string> */
    public array $downloadedFileIds = [];

    /** @var list<string> */
    public array $uploadedTypes = [];

    public bool $closeUploadedStreams = false;

    public function upload(TelegramUploadRequest $request): TelegramUploadedFile
    {
        $this->uploadedTypes[] = $request->type;

        if ($this->closeUploadedStreams && is_resource($request->contents)) {
            fclose($request->contents);
        }

        if (($this->uploadFailures[0] ?? null) === $request->type) {
            array_shift($this->uploadFailures);
            throw new RuntimeException('upload failed for ' . $request->type);
        }

        return new TelegramUploadedFile($request->type, 'uploaded-file-id-' . count($this->uploadedTypes), null, $request->chatId, count($this->uploadedTypes), null, $request->mimeType);
    }

    public function downloadStream(string $fileId): mixed
    {
        $this->downloadedFileIds[] = $fileId;

        if ($this->failDownloadFor === $fileId) {
            throw new RuntimeException('download failed for ' . $fileId);
        }

        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to create fake stream.');
        }

        fwrite($stream, $this->downloads[$fileId] ?? '');
        rewind($stream);

        return $stream;
    }
}
