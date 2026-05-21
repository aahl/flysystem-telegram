<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Stream;

use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Telegram\TelegramClientInterface;

final class ChunkedReadStreamRegistry
{
    /**
     * @var array<string, array{client: TelegramClientInterface, file: StoredFile}>
     */
    private static array $entries = [];

    public static function put(TelegramClientInterface $client, StoredFile $file): string
    {
        $token = bin2hex(random_bytes(16));
        self::$entries[$token] = ['client' => $client, 'file' => $file];

        return $token;
    }

    /**
     * @return array{client: TelegramClientInterface, file: StoredFile}|null
     */
    public static function take(string $token): ?array
    {
        return self::$entries[$token] ?? null;
    }

    public static function release(string $token): void
    {
        unset(self::$entries[$token]);
    }

    public static function count(): int
    {
        return count(self::$entries);
    }
}
