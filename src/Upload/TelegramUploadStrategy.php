<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Upload;

final class TelegramUploadStrategy
{
    public function __construct(
        public readonly string $type,
        public readonly bool $chunked,
        public readonly int $chunkSize,
    ) {
    }
}
