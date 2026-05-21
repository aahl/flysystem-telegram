<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Config;

use Aahl\FlysystemTelegram\Exception\InvalidConfiguration;
use League\Flysystem\Visibility;

final class TelegramAdapterConfig
{
    /**
     * @param array<string, int> $typeSizeLimits
     */
    public function __construct(
        public readonly ?string $botToken = null,
        public readonly ?string $chatId = null,
        public readonly ?string $databasePath = null,
        public readonly string $defaultVisibility = Visibility::PRIVATE,
        public readonly bool $enableChunking = true,
        public readonly int $maxFileSize = 50 * 1024 * 1024,
        public readonly int $chunkSize = 49 * 1024 * 1024,
        public readonly string $chunkStreamProtocol = 'flysystem-telegram',
        public readonly UploadTypeStrategy $uploadTypeStrategy = UploadTypeStrategy::Auto,
        public readonly array $typeSizeLimits = [],
        public readonly string $apiBaseUri = 'https://api.telegram.org',
        public readonly string $fileBaseUri = 'https://api.telegram.org/file',
        public readonly float $timeout = 30.0,
    ) {
    }

    public function resolveBotToken(): string
    {
        $value = $this->firstNonEmpty(
            $this->botToken,
            getenv('FLYSYSTEM_TELEGRAM_BOT_TOKEN') ?: null,
            getenv('TELEGRAM_BOT_TOKEN') ?: null,
        );

        if ($value === null) {
            throw new InvalidConfiguration('Missing Telegram bot token. Pass botToken explicitly or set FLYSYSTEM_TELEGRAM_BOT_TOKEN or TELEGRAM_BOT_TOKEN.');
        }

        return $value;
    }

    public function resolveChatId(): string
    {
        $value = $this->firstNonEmpty(
            $this->chatId,
            getenv('FLYSYSTEM_TELEGRAM_CHAT_ID') ?: null,
        );

        if ($value === null) {
            throw new InvalidConfiguration('Missing Telegram chat id. Pass chatId explicitly or set FLYSYSTEM_TELEGRAM_CHAT_ID.');
        }

        return $value;
    }

    public function resolveDatabasePath(): string
    {
        if ($this->databasePath !== null && $this->databasePath !== '') {
            return $this->databasePath;
        }

        $cwd = getcwd();

        if ($cwd === false) {
            throw new InvalidConfiguration('Unable to resolve default SQLite database path because getcwd() failed.');
        }

        return rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.flysystem-telegram.sqlite';
    }

    private function firstNonEmpty(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
