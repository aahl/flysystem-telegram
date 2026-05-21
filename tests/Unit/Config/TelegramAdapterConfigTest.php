<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Unit\Config;

use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Exception\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class TelegramAdapterConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FLYSYSTEM_TELEGRAM_BOT_TOKEN');
        putenv('TELEGRAM_BOT_TOKEN');
        putenv('FLYSYSTEM_TELEGRAM_CHAT_ID');
    }

    public function testExplicitValuesWinOverEnvironment(): void
    {
        putenv('FLYSYSTEM_TELEGRAM_BOT_TOKEN=env-token');
        putenv('TELEGRAM_BOT_TOKEN=common-token');
        putenv('FLYSYSTEM_TELEGRAM_CHAT_ID=env-chat');

        $config = new TelegramAdapterConfig(
            botToken: 'explicit-token',
            chatId: 'explicit-chat',
            databasePath: '/tmp/custom.sqlite',
        );

        self::assertSame('explicit-token', $config->resolveBotToken());
        self::assertSame('explicit-chat', $config->resolveChatId());
        self::assertSame('/tmp/custom.sqlite', $config->resolveDatabasePath());
    }

    public function testBotTokenUsesPrefixedEnvironmentBeforeCommonEnvironment(): void
    {
        putenv('FLYSYSTEM_TELEGRAM_BOT_TOKEN=prefixed-token');
        putenv('TELEGRAM_BOT_TOKEN=common-token');

        self::assertSame('prefixed-token', (new TelegramAdapterConfig(chatId: 'chat'))->resolveBotToken());
    }

    public function testBotTokenFallsBackToCommonEnvironment(): void
    {
        putenv('TELEGRAM_BOT_TOKEN=common-token');

        self::assertSame('common-token', (new TelegramAdapterConfig(chatId: 'chat'))->resolveBotToken());
    }

    public function testChatIdUsesOnlyPrefixedEnvironment(): void
    {
        putenv('TELEGRAM_CHAT_ID=ignored-chat');
        putenv('FLYSYSTEM_TELEGRAM_CHAT_ID=storage-chat');

        self::assertSame('storage-chat', (new TelegramAdapterConfig(botToken: 'token'))->resolveChatId());
    }

    public function testDatabasePathDoesNotReadEnvironment(): void
    {
        putenv('FLYSYSTEM_TELEGRAM_DATABASE_PATH=/tmp/ignored.sqlite');

        $config = new TelegramAdapterConfig(botToken: 'token', chatId: 'chat');

        self::assertStringEndsWith('/.flysystem-telegram.sqlite', $config->resolveDatabasePath());
        self::assertNotSame('/tmp/ignored.sqlite', $config->resolveDatabasePath());
    }

    public function testMissingBotTokenThrowsConfigurationException(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Missing Telegram bot token');

        (new TelegramAdapterConfig(chatId: 'chat'))->resolveBotToken();
    }

    public function testMissingChatIdThrowsConfigurationException(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Missing Telegram chat id');

        (new TelegramAdapterConfig(botToken: 'token'))->resolveChatId();
    }
}
