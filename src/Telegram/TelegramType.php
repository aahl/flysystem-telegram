<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Telegram;

final class TelegramType
{
    public const PHOTO = 'photo';
    public const VIDEO = 'video';
    public const AUDIO = 'audio';
    public const ANIMATION = 'animation';
    public const DOCUMENT = 'document';

    public const DEFAULT_SIZE_LIMITS = [
        self::PHOTO => 10 * 1024 * 1024,
        self::VIDEO => 20 * 1024 * 1024,
        self::AUDIO => 20 * 1024 * 1024,
        self::ANIMATION => 20 * 1024 * 1024,
        self::DOCUMENT => 20 * 1024 * 1024,
    ];

    public static function assertValid(string $type): void
    {
        if (!in_array($type, [self::PHOTO, self::VIDEO, self::AUDIO, self::ANIMATION, self::DOCUMENT], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid Telegram file type "%s".', $type));
        }
    }
}
