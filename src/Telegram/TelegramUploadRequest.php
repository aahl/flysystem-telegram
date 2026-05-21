<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Telegram;

final class TelegramUploadRequest
{
    public function __construct(
        public readonly string $type,
        public readonly string $chatId,
        public readonly mixed $contents,
        public readonly ?string $filename = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $replyToMessageId = null,
    ) {
    }
}
