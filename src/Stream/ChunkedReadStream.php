<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Stream;

use Aahl\FlysystemTelegram\Exception\ChunkedStreamException;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Telegram\TelegramClientInterface;

final class ChunkedReadStream
{
    /** @var array<string, true> */
    private static array $registeredProtocols = [];

    public static function register(string $protocol): void
    {
        self::assertValidProtocol($protocol);

        if (isset(self::$registeredProtocols[$protocol])) {
            return;
        }

        if (in_array($protocol, stream_get_wrappers(), true)) {
            throw new ChunkedStreamException(sprintf('Stream protocol "%s" is already registered.', $protocol));
        }

        if (!stream_wrapper_register($protocol, ChunkedReadStreamWrapper::class)) {
            throw new ChunkedStreamException(sprintf('Unable to register stream protocol "%s".', $protocol));
        }

        self::$registeredProtocols[$protocol] = true;
    }

    public static function open(string $protocol, TelegramClientInterface $telegramClient, StoredFile $storedFile): mixed
    {
        self::register($protocol);
        $token = ChunkedReadStreamRegistry::put($telegramClient, $storedFile);
        $stream = @fopen($protocol . '://chunked/' . $token, 'rb');

        if ($stream === false) {
            ChunkedReadStreamRegistry::release($token);
            throw new ChunkedStreamException('Unable to open chunked read stream.');
        }

        return $stream;
    }

    private static function assertValidProtocol(string $protocol): void
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*$/', $protocol) !== 1) {
            throw new ChunkedStreamException('Invalid stream protocol: ' . $protocol);
        }
    }
}
