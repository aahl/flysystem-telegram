<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

final class ChunkMetadata
{
    public function __construct(
        public readonly string $path,
        public readonly int $index,
        public readonly string $type,
        public readonly int $size,
        public readonly string $telegramFileId,
        public readonly ?string $telegramFileUniqueId,
        public readonly string $telegramChatId,
        public readonly ?int $telegramMessageId,
    ) {
    }
}
