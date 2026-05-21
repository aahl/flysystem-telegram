<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

final class FileMetadata
{
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly int $size,
        public readonly ?string $mimeType,
        public readonly ?string $visibility,
        public readonly int $lastModified,
        public readonly ?string $telegramFileId,
        public readonly ?string $telegramFileUniqueId,
        public readonly string $telegramChatId,
        public readonly ?int $telegramMessageId,
        public readonly bool $isChunked = false,
        public readonly ?int $chunkSize = null,
        public readonly ?int $chunkCount = null,
    ) {
    }
}
