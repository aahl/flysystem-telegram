<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Unit\Upload;

use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Config\UploadTypeStrategy;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\Upload\UploadStrategyResolver;
use PHPUnit\Framework\TestCase;

final class UploadStrategyResolverTest extends TestCase
{
    private UploadStrategyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new UploadStrategyResolver();
    }

    public function testMimeTypeWinsOverExtension(): void
    {
        $strategy = $this->resolver->resolve('image.bin', 'video/mp4', 1024, new TelegramAdapterConfig(botToken: 'token', chatId: 'chat'));

        self::assertSame(TelegramType::VIDEO, $strategy->type);
        self::assertFalse($strategy->chunked);
    }

    public function testGifResolvesToAnimationBeforePhoto(): void
    {
        $strategy = $this->resolver->resolve('clip.gif', null, 1024, new TelegramAdapterConfig(botToken: 'token', chatId: 'chat'));

        self::assertSame(TelegramType::ANIMATION, $strategy->type);
    }

    public function testImageVideoAndAudioResolveToTypedUploads(): void
    {
        self::assertSame(TelegramType::PHOTO, $this->resolver->resolve('a.jpg', null, 1024, new TelegramAdapterConfig(botToken: 'token', chatId: 'chat'))->type);
        self::assertSame(TelegramType::VIDEO, $this->resolver->resolve('a.mp4', null, 1024, new TelegramAdapterConfig(botToken: 'token', chatId: 'chat'))->type);
        self::assertSame(TelegramType::AUDIO, $this->resolver->resolve('a.mp3', null, 1024, new TelegramAdapterConfig(botToken: 'token', chatId: 'chat'))->type);
    }

    public function testUnknownTypeFallsBackToDocument(): void
    {
        $strategy = $this->resolver->resolve('archive.zip', null, 1024, new TelegramAdapterConfig(botToken: 'token', chatId: 'chat'));

        self::assertSame(TelegramType::DOCUMENT, $strategy->type);
        self::assertFalse($strategy->chunked);
    }

    public function testTypedLimitOverflowFallsBackToDocument(): void
    {
        $config = new TelegramAdapterConfig(
            botToken: 'token',
            chatId: 'chat',
            typeSizeLimits: [TelegramType::PHOTO => 100, TelegramType::DOCUMENT => 1000],
        );

        $strategy = $this->resolver->resolve('photo.jpg', 'image/jpeg', 500, $config);

        self::assertSame(TelegramType::DOCUMENT, $strategy->type);
        self::assertFalse($strategy->chunked);
    }

    public function testDocumentLimitOverflowUsesChunkedDocumentWhenEnabled(): void
    {
        $config = new TelegramAdapterConfig(
            botToken: 'token',
            chatId: 'chat',
            chunkSize: 900,
            typeSizeLimits: [TelegramType::DOCUMENT => 1000],
        );

        $strategy = $this->resolver->resolve('big.bin', null, 2500, $config);

        self::assertSame(TelegramType::DOCUMENT, $strategy->type);
        self::assertTrue($strategy->chunked);
        self::assertSame(900, $strategy->chunkSize);
    }

    public function testChunkSizeIsClampedToDocumentLimit(): void
    {
        $config = new TelegramAdapterConfig(
            botToken: 'token',
            chatId: 'chat',
            chunkSize: 5000,
            typeSizeLimits: [TelegramType::DOCUMENT => 1000],
        );

        $strategy = $this->resolver->resolve('big.bin', null, 2500, $config);

        self::assertSame(1000, $strategy->chunkSize);
    }

    public function testDocumentLimitOverflowFailsWhenChunkingDisabled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File exceeds Telegram document size limit and chunking is disabled.');

        $config = new TelegramAdapterConfig(
            botToken: 'token',
            chatId: 'chat',
            enableChunking: false,
            typeSizeLimits: [TelegramType::DOCUMENT => 1000],
        );

        $this->resolver->resolve('big.bin', null, 2500, $config);
    }

    public function testMaxFileSizeCapsDocumentUploadLimit(): void
    {
        $config = new TelegramAdapterConfig(
            botToken: 'token',
            chatId: 'chat',
            maxFileSize: 600,
            chunkSize: 500,
            typeSizeLimits: [TelegramType::DOCUMENT => 1000],
        );

        $strategy = $this->resolver->resolve('big.bin', null, 700, $config);

        self::assertSame(TelegramType::DOCUMENT, $strategy->type);
        self::assertTrue($strategy->chunked);
        self::assertSame(500, $strategy->chunkSize);
    }

    public function testDocumentOnlyStrategyAlwaysUsesDocument(): void
    {
        $config = new TelegramAdapterConfig(
            botToken: 'token',
            chatId: 'chat',
            uploadTypeStrategy: UploadTypeStrategy::DocumentOnly,
        );

        $strategy = $this->resolver->resolve('photo.jpg', 'image/jpeg', 1024, $config);

        self::assertSame(TelegramType::DOCUMENT, $strategy->type);
    }
}
