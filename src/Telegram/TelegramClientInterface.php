<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Telegram;

interface TelegramClientInterface
{
    public function upload(TelegramUploadRequest $request): TelegramUploadedFile;

    public function downloadStream(string $fileId): mixed;
}
