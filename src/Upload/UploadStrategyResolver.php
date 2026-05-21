<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Upload;

use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Config\UploadTypeStrategy;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use RuntimeException;

final class UploadStrategyResolver
{
    /**
     * @var array<string, string>
     */
    private const EXTENSION_MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
        'heic' => 'image/heic',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'webm' => 'video/webm',
        'avi' => 'video/x-msvideo',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'flac' => 'audio/flac',
        'wav' => 'audio/wav',
    ];

    public function resolve(string $path, ?string $mimeType, int $size, TelegramAdapterConfig $config): TelegramUploadStrategy
    {
        $limits = array_replace(TelegramType::DEFAULT_SIZE_LIMITS, $config->typeSizeLimits);
        $documentLimit = min($limits[TelegramType::DOCUMENT], $config->maxFileSize);
        $chunkSize = min($config->chunkSize, $documentLimit);

        $candidate = $config->uploadTypeStrategy === UploadTypeStrategy::DocumentOnly
            ? TelegramType::DOCUMENT
            : $this->inferType($path, $mimeType);

        $candidateLimit = $candidate === TelegramType::DOCUMENT ? $documentLimit : $limits[$candidate];

        if ($size <= $candidateLimit) {
            return new TelegramUploadStrategy($candidate, false, $chunkSize);
        }

        if ($candidate !== TelegramType::DOCUMENT && $size <= $documentLimit) {
            return new TelegramUploadStrategy(TelegramType::DOCUMENT, false, $chunkSize);
        }

        if ($config->enableChunking) {
            return new TelegramUploadStrategy(TelegramType::DOCUMENT, true, $chunkSize);
        }

        throw new RuntimeException('File exceeds Telegram document size limit and chunking is disabled.');
    }

    public function resolveMimeType(string $path, ?string $configuredMimeType): ?string
    {
        if ($configuredMimeType !== null && $configuredMimeType !== '') {
            return $configuredMimeType;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::EXTENSION_MIME_TYPES[$extension] ?? null;
    }

    private function inferType(string $path, ?string $mimeType): string
    {
        $resolvedMimeType = $this->resolveMimeType($path, $mimeType);

        if ($resolvedMimeType === 'image/gif') {
            return TelegramType::ANIMATION;
        }

        if ($resolvedMimeType !== null && str_starts_with($resolvedMimeType, 'image/')) {
            return TelegramType::PHOTO;
        }

        if ($resolvedMimeType !== null && str_starts_with($resolvedMimeType, 'video/')) {
            return TelegramType::VIDEO;
        }

        if ($resolvedMimeType !== null && str_starts_with($resolvedMimeType, 'audio/')) {
            return TelegramType::AUDIO;
        }

        return TelegramType::DOCUMENT;
    }
}
