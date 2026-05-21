<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Telegram;

final class TelegramUploadedFile
{
    public function __construct(
        public readonly string $type,
        public readonly string $fileId,
        public readonly ?string $fileUniqueId,
        public readonly string $chatId,
        public readonly ?int $messageId,
        public readonly ?int $size,
        public readonly ?string $mimeType,
    ) {
    }
}
