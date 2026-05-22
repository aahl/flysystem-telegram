# Flysystem Telegram Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `aahl/flysystem-telegram`, a PHP 8.1+ Flysystem 3.x adapter backed by Telegram Bot API file storage and local SQLite metadata.

**Architecture:** `TelegramAdapter` implements Flysystem and coordinates upload strategy, chunking, Telegram API calls, metadata persistence, and exception conversion. Telegram bytes live in a group/channel; `MetadataStore` maps paths to Telegram file identifiers, with `SqliteMetadataStore` as the default implementation.

**Tech Stack:** PHP 8.1, Flysystem 3.x, Guzzle 7, PDO SQLite, PHPUnit, PHPStan, PHP-CS-Fixer.

---

## Confirmed Spec

Read before executing:

- `docs/superpowers/specs/2026-05-21-flysystem-telegram-design.md`

Key constraints from the spec:

- Package: `aahl/flysystem-telegram`
- Namespace: `Aahl\FlysystemTelegram`
- `databasePath` does not read an environment variable. Resolution is explicit config value, then `getcwd() . '/.flysystem-telegram.sqlite'`.
- `botToken` reads explicit value, then `FLYSYSTEM_TELEGRAM_BOT_TOKEN`, then `TELEGRAM_BOT_TOKEN`.
- `chatId` reads explicit value, then `FLYSYSTEM_TELEGRAM_CHAT_ID` only.
- SQLite tables are `files`, `chunks`, and `schema_migrations`.
- The Telegram storage type column and PHP property are named `type`.
- `delete()` removes metadata only; it never deletes Telegram messages.
- Chunked `readStream()` is lazy and uses a custom stream wrapper.

---

## File Structure

Create this structure:

```text
composer.json
phpunit.xml.dist
phpstan.neon.dist
.php-cs-fixer.dist.php
src/
  ChunkManager.php
  Config/TelegramAdapterConfig.php
  Config/UploadTypeStrategy.php
  Exception/ChunkedStreamException.php
  Exception/InvalidConfiguration.php
  Exception/MetadataStoreException.php
  Exception/TelegramApiException.php
  Metadata/ChunkMetadata.php
  Metadata/DirectoryMetadata.php
  Metadata/FileMetadata.php
  Metadata/StoredFile.php
  Metadata/MetadataStore.php
  Metadata/SqliteMetadataStore.php
  Stream/ChunkedReadStream.php
  Stream/ChunkedReadStreamRegistry.php
  Stream/ChunkedReadStreamWrapper.php
  Telegram/GuzzleTelegramClient.php
  Telegram/TelegramClientInterface.php
  Telegram/TelegramUploadedFile.php
  Telegram/TelegramUploadRequest.php
  Telegram/TelegramType.php
  TelegramAdapter.php
  Upload/TelegramUploadStrategy.php
  Upload/UploadStrategyResolver.php
tests/
  Fake/FakeMetadataStore.php
  Fake/FakeTelegramClient.php
  Unit/Config/TelegramAdapterConfigTest.php
  Unit/Upload/UploadStrategyResolverTest.php
  Unit/ChunkManagerTest.php
  Unit/Stream/ChunkedReadStreamTest.php
  Unit/TelegramAdapterTest.php
  Integration/Metadata/SqliteMetadataStoreTest.php
  Integration/Telegram/GuzzleTelegramClientTest.php
```

Responsibilities:

- `Config/*`: runtime config and config resolution.
- `Exception/*`: package exceptions before conversion to Flysystem exceptions.
- `Metadata/*`: public metadata interface, metadata value objects, SQLite implementation.
- `Telegram/*`: Telegram API abstraction and Guzzle implementation.
- `Upload/*`: MIME/type/size strategy decisions.
- `Stream/*`: lazy chunked stream resource implementation.
- `TelegramAdapter.php`: Flysystem adapter boundary.
- `tests/Fake/*`: reusable test doubles for adapter and stream tests.

---

### Task 1: Project scaffold and tooling

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `phpstan.neon.dist`
- Create: `.php-cs-fixer.dist.php`

- [ ] **Step 1: Create Composer package metadata**

Create `composer.json`:

```json
{
  "name": "aahl/flysystem-telegram",
  "description": "Flysystem adapter backed by Telegram Bot API file storage.",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": "^8.1",
    "ext-pdo": "*",
    "ext-pdo_sqlite": "*",
    "guzzlehttp/guzzle": "^7.0",
    "league/flysystem": "^3.0"
  },
  "require-dev": {
    "friendsofphp/php-cs-fixer": "^3.64",
    "phpstan/phpstan": "^1.12",
    "phpunit/phpunit": "^10.5"
  },
  "autoload": {
    "psr-4": {
      "Aahl\\FlysystemTelegram\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Aahl\\FlysystemTelegram\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit",
    "analyse": "phpstan analyse",
    "cs": "php-cs-fixer fix --dry-run --diff",
    "cs:fix": "php-cs-fixer fix"
  },
  "config": {
    "sort-packages": true
  }
}
```

- [ ] **Step 2: Create PHPUnit config**

Create `phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="default">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Create PHPStan config**

Create `phpstan.neon.dist`:

```neon
parameters:
    level: 6
    paths:
        - src
        - tests
```

- [ ] **Step 4: Create PHP-CS-Fixer config**

Create `.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
```

- [ ] **Step 5: Install dependencies**

Run:

```bash
composer install
```

Expected: `vendor/` and `composer.lock` are created without dependency conflicts.

- [ ] **Step 6: Validate composer metadata**

Run:

```bash
composer validate
```

Expected: PASS.

- [ ] **Step 7: Commit scaffold**

Run:

```bash
git add composer.json composer.lock phpunit.xml.dist phpstan.neon.dist .php-cs-fixer.dist.php
git commit -m "chore: scaffold package tooling"
```

---

### Task 2: Config, enums, exceptions, and metadata value objects

**Files:**
- Create: `src/Config/UploadTypeStrategy.php`
- Create: `src/Config/TelegramAdapterConfig.php`
- Create: `src/Exception/InvalidConfiguration.php`
- Create: `src/Exception/MetadataStoreException.php`
- Create: `src/Exception/TelegramApiException.php`
- Create: `src/Exception/ChunkedStreamException.php`
- Create: `src/Telegram/TelegramType.php`
- Create: `src/Metadata/FileMetadata.php`
- Create: `src/Metadata/ChunkMetadata.php`
- Create: `src/Metadata/StoredFile.php`
- Create: `src/Metadata/DirectoryMetadata.php`
- Create: `tests/Unit/Config/TelegramAdapterConfigTest.php`

- [ ] **Step 1: Write failing config tests**

Create `tests/Unit/Config/TelegramAdapterConfigTest.php`:

```php
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
```

- [ ] **Step 2: Run config tests and verify failure**

Run:

```bash
vendor/bin/phpunit tests/Unit/Config/TelegramAdapterConfigTest.php
```

Expected: FAIL because `TelegramAdapterConfig` does not exist.

- [ ] **Step 3: Add exceptions**

Create `src/Exception/InvalidConfiguration.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Exception;

use RuntimeException;

final class InvalidConfiguration extends RuntimeException
{
}
```

Create `src/Exception/MetadataStoreException.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Exception;

use RuntimeException;

final class MetadataStoreException extends RuntimeException
{
}
```

Create `src/Exception/TelegramApiException.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Exception;

use RuntimeException;

final class TelegramApiException extends RuntimeException
{
}
```

Create `src/Exception/ChunkedStreamException.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Exception;

use RuntimeException;

final class ChunkedStreamException extends RuntimeException
{
}
```

- [ ] **Step 4: Add config enum and Telegram type constants**

Create `src/Config/UploadTypeStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Config;

enum UploadTypeStrategy: string
{
    case Auto = 'auto';
    case DocumentOnly = 'document_only';
}
```

Create `src/Telegram/TelegramType.php`:

```php
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
```

- [ ] **Step 5: Add config implementation**

Create `src/Config/TelegramAdapterConfig.php`:

```php
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
        public readonly int $chunkSize = 20 * 1024 * 1024,
        public readonly string $chunkStreamProtocol = 'flysystem-telegram',
        public readonly UploadTypeStrategy $uploadTypeStrategy = UploadTypeStrategy::Auto,
        public readonly array $typeSizeLimits = [],
        public readonly string $apiBaseUri = 'https://api.telegram.org',
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
```

- [ ] **Step 6: Add metadata value objects**

Create `src/Metadata/FileMetadata.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

final class FileMetadata
{
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly int $size,
        public readonly ?string $mimeType,
        public readonly ?string $visibility,
        public readonly int $lastModified,
        public readonly ?string $telegramFileId,
        public readonly ?string $telegramFileUniqueId,
        public readonly string $telegramChatId,
        public readonly ?int $telegramMessageId,
        public readonly bool $isChunked = false,
        public readonly ?int $chunkSize = null,
        public readonly ?int $chunkCount = null,
    ) {
    }
}
```

Create `src/Metadata/ChunkMetadata.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

final class ChunkMetadata
{
    public function __construct(
        public readonly string $path,
        public readonly int $index,
        public readonly string $type,
        public readonly int $size,
        public readonly string $telegramFileId,
        public readonly ?string $telegramFileUniqueId,
        public readonly string $telegramChatId,
        public readonly ?int $telegramMessageId,
    ) {
    }
}
```

Create `src/Metadata/StoredFile.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

final class StoredFile
{
    /**
     * @param list<ChunkMetadata> $chunks
     */
    public function __construct(
        public readonly FileMetadata $metadata,
        public readonly array $chunks = [],
    ) {
    }

    public function isChunked(): bool
    {
        return $this->metadata->isChunked;
    }
}
```

Create `src/Metadata/DirectoryMetadata.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

final class DirectoryMetadata
{
    public function __construct(
        public readonly string $path,
    ) {
    }
}
```

- [ ] **Step 7: Run config tests and verify pass**

Run:

```bash
vendor/bin/phpunit tests/Unit/Config/TelegramAdapterConfigTest.php
```

Expected: PASS.

- [ ] **Step 8: Run static checks for created files**

Run:

```bash
composer analyse
```

Expected: PASS or only fail because later referenced classes do not exist. If it fails for created files, fix those errors before continuing.

- [ ] **Step 9: Commit config and value objects**

Run:

```bash
git add src/Config src/Exception src/Telegram/TelegramType.php src/Metadata/FileMetadata.php src/Metadata/ChunkMetadata.php src/Metadata/StoredFile.php src/Metadata/DirectoryMetadata.php tests/Unit/Config/TelegramAdapterConfigTest.php
git commit -m "feat: add config and metadata values"
```

---

### Task 3: Upload strategy resolver

**Files:**
- Create: `src/Upload/TelegramUploadStrategy.php`
- Create: `src/Upload/UploadStrategyResolver.php`
- Create: `tests/Unit/Upload/UploadStrategyResolverTest.php`

- [ ] **Step 1: Write failing resolver tests**

Create `tests/Unit/Upload/UploadStrategyResolverTest.php`:

```php
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
```

- [ ] **Step 2: Run resolver tests and verify failure**

Run:

```bash
vendor/bin/phpunit tests/Unit/Upload/UploadStrategyResolverTest.php
```

Expected: FAIL because upload strategy classes do not exist.

- [ ] **Step 3: Add strategy value object**

Create `src/Upload/TelegramUploadStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Upload;

final class TelegramUploadStrategy
{
    public function __construct(
        public readonly string $type,
        public readonly bool $chunked,
        public readonly int $chunkSize,
    ) {
    }
}
```

- [ ] **Step 4: Add upload strategy resolver**

Create `src/Upload/UploadStrategyResolver.php`:

```php
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

        if ($size <= $limits[$candidate]) {
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

        if ($resolvedMimeType === 'image/gif' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'gif') {
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
```

- [ ] **Step 5: Run resolver tests and verify pass**

Run:

```bash
vendor/bin/phpunit tests/Unit/Upload/UploadStrategyResolverTest.php
```

Expected: PASS.

- [ ] **Step 6: Run all tests created so far**

Run:

```bash
composer test
```

Expected: PASS.

- [ ] **Step 7: Commit upload strategy**

Run:

```bash
git add src/Upload tests/Unit/Upload
git commit -m "feat: resolve Telegram upload strategy"
```

---

### Task 4: Chunk manager

**Files:**
- Create: `src/ChunkManager.php`
- Create: `tests/Unit/ChunkManagerTest.php`

- [ ] **Step 1: Write failing chunk manager tests**

Create `tests/Unit/ChunkManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Unit;

use Aahl\FlysystemTelegram\ChunkManager;
use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChunkManagerTest extends TestCase
{
    public function testShouldChunkUsesMaxFileSize(): void
    {
        $manager = new ChunkManager(100, 40);

        self::assertFalse($manager->shouldChunk(100));
        self::assertTrue($manager->shouldChunk(101));
    }

    public function testSplitStreamYieldsChunkStreamsWithSizes(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'abcdefghi');
        rewind($stream);

        $parts = iterator_to_array((new ChunkManager(5, 4))->splitStream($stream));

        self::assertCount(3, $parts);
        self::assertSame(0, $parts[0]->index);
        self::assertSame(4, $parts[0]->size);
        self::assertSame('abcd', stream_get_contents($parts[0]->stream));
        self::assertSame(1, $parts[1]->index);
        self::assertSame(4, $parts[1]->size);
        self::assertSame('efgh', stream_get_contents($parts[1]->stream));
        self::assertSame(2, $parts[2]->index);
        self::assertSame(1, $parts[2]->size);
        self::assertSame('i', stream_get_contents($parts[2]->stream));
    }

    public function testValidateStoredFileAcceptsConsistentChunks(): void
    {
        $file = new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 9, null, 'private', 100, null, null, 'chat', null, true, 4, 3),
            [
                new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, 'chat', 10),
                new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 4, 'file-1', null, 'chat', 11),
                new ChunkMetadata('big.bin', 2, TelegramType::DOCUMENT, 1, 'file-2', null, 'chat', 12),
            ],
        );

        (new ChunkManager(5, 4))->validateStoredFile($file);

        self::assertTrue(true);
    }

    public function testValidateStoredFileRejectsMissingChunk(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chunk count mismatch.');

        $file = new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 8, null, 'private', 100, null, null, 'chat', null, true, 4, 2),
            [new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, 'chat', 10)],
        );

        (new ChunkManager(5, 4))->validateStoredFile($file);
    }

    public function testValidateStoredFileRejectsNonContiguousIndexes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chunk index mismatch.');

        $file = new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 8, null, 'private', 100, null, null, 'chat', null, true, 4, 2),
            [
                new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, 'chat', 10),
                new ChunkMetadata('big.bin', 2, TelegramType::DOCUMENT, 4, 'file-2', null, 'chat', 12),
            ],
        );

        (new ChunkManager(5, 4))->validateStoredFile($file);
    }

    public function testValidateStoredFileRejectsSizeMismatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chunk size sum mismatch.');

        $file = new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 9, null, 'private', 100, null, null, 'chat', null, true, 4, 2),
            [
                new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, 'chat', 10),
                new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 4, 'file-1', null, 'chat', 11),
            ],
        );

        (new ChunkManager(5, 4))->validateStoredFile($file);
    }
}
```

- [ ] **Step 2: Run chunk tests and verify failure**

Run:

```bash
vendor/bin/phpunit tests/Unit/ChunkManagerTest.php
```

Expected: FAIL because `ChunkManager` does not exist.

- [ ] **Step 3: Add chunk manager implementation**

Create `src/ChunkManager.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram;

use Aahl\FlysystemTelegram\Metadata\StoredFile;
use RuntimeException;

final class ChunkManager
{
    public function __construct(
        private readonly int $maxFileSize,
        private readonly int $chunkSize,
    ) {
    }

    public function shouldChunk(int $size): bool
    {
        return $size > $this->maxFileSize;
    }

    /**
     * @return iterable<ChunkPart>
     */
    public function splitStream(mixed $stream, ?int $chunkSize = null): iterable
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Expected readable stream resource.');
        }

        $effectiveChunkSize = $chunkSize ?? $this->chunkSize;
        $index = 0;

        while (!feof($stream)) {
            $contents = fread($stream, $effectiveChunkSize);

            if ($contents === false) {
                throw new RuntimeException('Unable to read source stream.');
            }

            if ($contents === '') {
                break;
            }

            $chunkStream = fopen('php://temp', 'w+b');

            if ($chunkStream === false) {
                throw new RuntimeException('Unable to create chunk stream.');
            }

            fwrite($chunkStream, $contents);
            rewind($chunkStream);

            yield new ChunkPart($index, strlen($contents), $chunkStream);

            $index++;
        }
    }

    public function validateStoredFile(StoredFile $file): void
    {
        if (!$file->isChunked()) {
            return;
        }

        $expectedCount = $file->metadata->chunkCount;

        if ($expectedCount === null || count($file->chunks) !== $expectedCount) {
            throw new RuntimeException('Chunk count mismatch.');
        }

        $size = 0;

        foreach ($file->chunks as $expectedIndex => $chunk) {
            if ($chunk->index !== $expectedIndex) {
                throw new RuntimeException('Chunk index mismatch.');
            }

            $size += $chunk->size;
        }

        if ($size !== $file->metadata->size) {
            throw new RuntimeException('Chunk size sum mismatch.');
        }
    }
}

final class ChunkPart
{
    public function __construct(
        public readonly int $index,
        public readonly int $size,
        public readonly mixed $stream,
    ) {
    }
}
```

- [ ] **Step 4: Run chunk tests and verify pass**

Run:

```bash
vendor/bin/phpunit tests/Unit/ChunkManagerTest.php
```

Expected: PASS.

- [ ] **Step 5: Run all tests**

Run:

```bash
composer test
```

Expected: PASS.

- [ ] **Step 6: Commit chunk manager**

Run:

```bash
git add src/ChunkManager.php tests/Unit/ChunkManagerTest.php
git commit -m "feat: add chunk management"
```

---

### Task 5: MetadataStore interface and SQLite initialization/write/read/delete

**Files:**
- Create: `src/Metadata/MetadataStore.php`
- Create: `src/Metadata/SqliteMetadataStore.php`
- Create: `tests/Integration/Metadata/SqliteMetadataStoreTest.php`

- [ ] **Step 1: Write failing SQLite tests for init/write/read/delete**

Create `tests/Integration/Metadata/SqliteMetadataStoreTest.php` with these initial tests:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Integration\Metadata;

use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\SqliteMetadataStore;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

final class SqliteMetadataStoreTest extends TestCase
{
    private string $databasePath;
    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'flysystem-telegram-');
        self::assertIsString($this->databasePath);
        unlink($this->databasePath);
        $this->store = new SqliteMetadataStore($this->databasePath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testWriteAndReadNonChunkedFile(): void
    {
        $file = new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, 'text/plain', Visibility::PRIVATE, 123, 'file-id', 'unique-id', '-100', 77);

        $this->store->write($file);
        $stored = $this->store->read('docs/a.txt');

        self::assertNotNull($stored);
        self::assertSame('docs/a.txt', $stored->metadata->path);
        self::assertSame(TelegramType::DOCUMENT, $stored->metadata->type);
        self::assertSame(5, $stored->metadata->size);
        self::assertSame('file-id', $stored->metadata->telegramFileId);
        self::assertSame([], $stored->chunks);
    }

    public function testReadMissingPathReturnsNull(): void
    {
        self::assertNull($this->store->read('missing.txt'));
    }

    public function testFileExistsReturnsExpectedBoolean(): void
    {
        self::assertFalse($this->store->fileExists('docs/a.txt'));

        $this->store->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, null, null, 123, 'file-id', null, '-100', 77));

        self::assertTrue($this->store->fileExists('docs/a.txt'));
    }

    public function testWriteChunkedFileAndReadChunksInOrder(): void
    {
        $file = new FileMetadata('big.bin', TelegramType::DOCUMENT, 9, null, Visibility::PRIVATE, 123, null, null, '-100', null, true, 4, 3);
        $chunks = [
            new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, '-100', 10),
            new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 4, 'file-1', null, '-100', 11),
            new ChunkMetadata('big.bin', 2, TelegramType::DOCUMENT, 1, 'file-2', null, '-100', 12),
        ];

        $this->store->write($file, $chunks);
        $stored = $this->store->read('big.bin');

        self::assertNotNull($stored);
        self::assertTrue($stored->isChunked());
        self::assertNull($stored->metadata->telegramFileId);
        self::assertCount(3, $stored->chunks);
        self::assertSame(['file-0', 'file-1', 'file-2'], array_map(static fn (ChunkMetadata $chunk): string => $chunk->telegramFileId, $stored->chunks));
    }

    public function testWriteReplacesExistingFileAndOldChunks(): void
    {
        $this->store->write(
            new FileMetadata('same.bin', TelegramType::DOCUMENT, 8, null, null, 100, null, null, '-100', null, true, 4, 2),
            [
                new ChunkMetadata('same.bin', 0, TelegramType::DOCUMENT, 4, 'old-0', null, '-100', 10),
                new ChunkMetadata('same.bin', 1, TelegramType::DOCUMENT, 4, 'old-1', null, '-100', 11),
            ],
        );

        $this->store->write(new FileMetadata('same.bin', TelegramType::DOCUMENT, 3, null, null, 200, 'new-file', null, '-100', 20));
        $stored = $this->store->read('same.bin');

        self::assertNotNull($stored);
        self::assertFalse($stored->isChunked());
        self::assertSame('new-file', $stored->metadata->telegramFileId);
        self::assertSame([], $stored->chunks);
    }

    public function testDeleteRemovesFileAndChunks(): void
    {
        $this->store->write(
            new FileMetadata('delete.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('delete.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->store->delete('delete.bin');

        self::assertNull($this->store->read('delete.bin'));
        self::assertFalse($this->store->fileExists('delete.bin'));
    }

    public function testDeleteMissingPathIsNoOp(): void
    {
        $this->store->delete('missing.txt');

        self::assertTrue(true);
    }
}
```

- [ ] **Step 2: Run SQLite tests and verify failure**

Run:

```bash
vendor/bin/phpunit tests/Integration/Metadata/SqliteMetadataStoreTest.php
```

Expected: FAIL because `MetadataStore` and `SqliteMetadataStore` do not exist.

- [ ] **Step 3: Add MetadataStore interface**

Create `src/Metadata/MetadataStore.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

interface MetadataStore
{
    /**
     * @param list<ChunkMetadata> $chunks
     */
    public function write(FileMetadata $file, array $chunks = []): void;

    public function read(string $path): ?StoredFile;

    public function delete(string $path): void;

    public function fileExists(string $path): bool;

    /**
     * @return iterable<FileMetadata|DirectoryMetadata>
     */
    public function listContents(string $path, bool $deep): iterable;

    public function move(string $source, string $destination): void;

    public function copy(string $source, string $destination): void;

    public function setVisibility(string $path, string $visibility): void;
}
```

- [ ] **Step 4: Add SQLite implementation with init/write/read/delete**

Create `src/Metadata/SqliteMetadataStore.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

use Aahl\FlysystemTelegram\Exception\MetadataStoreException;
use PDO;
use PDOException;
use Throwable;

final class SqliteMetadataStore implements MetadataStore
{
    private PDO $pdo;

    public function __construct(string $databasePath, ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? new PDO('sqlite:' . $databasePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initialize();
    }

    public function write(FileMetadata $file, array $chunks = []): void
    {
        $this->transaction(function () use ($file, $chunks): void {
            $this->pdo->prepare('DELETE FROM chunks WHERE path = :path')->execute(['path' => $file->path]);
            $this->pdo->prepare('DELETE FROM files WHERE path = :path')->execute(['path' => $file->path]);

            $now = time();
            $this->pdo->prepare('INSERT INTO files (path, type, size, mime_type, visibility, last_modified, telegram_file_id, telegram_file_unique_id, telegram_chat_id, telegram_message_id, is_chunked, chunk_size, chunk_count, created_at, updated_at) VALUES (:path, :type, :size, :mime_type, :visibility, :last_modified, :telegram_file_id, :telegram_file_unique_id, :telegram_chat_id, :telegram_message_id, :is_chunked, :chunk_size, :chunk_count, :created_at, :updated_at)')
                ->execute([
                    'path' => $file->path,
                    'type' => $file->type,
                    'size' => $file->size,
                    'mime_type' => $file->mimeType,
                    'visibility' => $file->visibility,
                    'last_modified' => $file->lastModified,
                    'telegram_file_id' => $file->telegramFileId,
                    'telegram_file_unique_id' => $file->telegramFileUniqueId,
                    'telegram_chat_id' => $file->telegramChatId,
                    'telegram_message_id' => $file->telegramMessageId,
                    'is_chunked' => $file->isChunked ? 1 : 0,
                    'chunk_size' => $file->chunkSize,
                    'chunk_count' => $file->chunkCount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            foreach ($chunks as $chunk) {
                $this->pdo->prepare('INSERT INTO chunks (path, chunk_index, type, size, telegram_file_id, telegram_file_unique_id, telegram_chat_id, telegram_message_id, created_at, updated_at) VALUES (:path, :chunk_index, :type, :size, :telegram_file_id, :telegram_file_unique_id, :telegram_chat_id, :telegram_message_id, :created_at, :updated_at)')
                    ->execute([
                        'path' => $chunk->path,
                        'chunk_index' => $chunk->index,
                        'type' => $chunk->type,
                        'size' => $chunk->size,
                        'telegram_file_id' => $chunk->telegramFileId,
                        'telegram_file_unique_id' => $chunk->telegramFileUniqueId,
                        'telegram_chat_id' => $chunk->telegramChatId,
                        'telegram_message_id' => $chunk->telegramMessageId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        });
    }

    public function read(string $path): ?StoredFile
    {
        try {
            $statement = $this->pdo->prepare('SELECT * FROM files WHERE path = :path');
            $statement->execute(['path' => $path]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return null;
            }

            $chunkStatement = $this->pdo->prepare('SELECT * FROM chunks WHERE path = :path ORDER BY chunk_index ASC');
            $chunkStatement->execute(['path' => $path]);
            $chunks = [];

            foreach ($chunkStatement->fetchAll(PDO::FETCH_ASSOC) as $chunkRow) {
                $chunks[] = $this->hydrateChunk($chunkRow);
            }

            return new StoredFile($this->hydrateFile($row), $chunks);
        } catch (PDOException $exception) {
            throw new MetadataStoreException('Unable to read metadata.', 0, $exception);
        }
    }

    public function delete(string $path): void
    {
        $this->transaction(function () use ($path): void {
            $this->pdo->prepare('DELETE FROM files WHERE path = :path')->execute(['path' => $path]);
        });
    }

    public function fileExists(string $path): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT 1 FROM files WHERE path = :path LIMIT 1');
            $statement->execute(['path' => $path]);

            return $statement->fetchColumn() !== false;
        } catch (PDOException $exception) {
            throw new MetadataStoreException('Unable to check metadata existence.', 0, $exception);
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination): void
    {
        throw new MetadataStoreException('Move is not implemented yet.');
    }

    public function copy(string $source, string $destination): void
    {
        throw new MetadataStoreException('Copy is not implemented yet.');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw new MetadataStoreException('Set visibility is not implemented yet.');
    }

    private function initialize(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS files (id INTEGER PRIMARY KEY, path TEXT NOT NULL UNIQUE, type TEXT NOT NULL, size INTEGER NOT NULL, mime_type TEXT NULL, visibility TEXT NULL, last_modified INTEGER NOT NULL, telegram_file_id TEXT NULL, telegram_file_unique_id TEXT NULL, telegram_chat_id TEXT NOT NULL, telegram_message_id INTEGER NULL, is_chunked INTEGER NOT NULL DEFAULT 0, chunk_size INTEGER NULL, chunk_count INTEGER NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS chunks (id INTEGER PRIMARY KEY, path TEXT NOT NULL, chunk_index INTEGER NOT NULL, type TEXT NOT NULL, size INTEGER NOT NULL, telegram_file_id TEXT NOT NULL, telegram_file_unique_id TEXT NULL, telegram_chat_id TEXT NOT NULL, telegram_message_id INTEGER NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, UNIQUE (path, chunk_index), FOREIGN KEY (path) REFERENCES files(path) ON DELETE CASCADE ON UPDATE CASCADE)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS files_path_prefix_idx ON files(path)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS chunks_path_idx ON chunks(path)');
    }

    private function transaction(callable $callback): void
    {
        try {
            $this->pdo->exec('BEGIN IMMEDIATE');
            $callback();
            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }

            if ($exception instanceof MetadataStoreException) {
                throw $exception;
            }

            throw new MetadataStoreException('Unable to write metadata.', 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateFile(array $row): FileMetadata
    {
        return new FileMetadata(
            (string) $row['path'],
            (string) $row['type'],
            (int) $row['size'],
            $row['mime_type'] === null ? null : (string) $row['mime_type'],
            $row['visibility'] === null ? null : (string) $row['visibility'],
            (int) $row['last_modified'],
            $row['telegram_file_id'] === null ? null : (string) $row['telegram_file_id'],
            $row['telegram_file_unique_id'] === null ? null : (string) $row['telegram_file_unique_id'],
            (string) $row['telegram_chat_id'],
            $row['telegram_message_id'] === null ? null : (int) $row['telegram_message_id'],
            (int) $row['is_chunked'] === 1,
            $row['chunk_size'] === null ? null : (int) $row['chunk_size'],
            $row['chunk_count'] === null ? null : (int) $row['chunk_count'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateChunk(array $row): ChunkMetadata
    {
        return new ChunkMetadata(
            (string) $row['path'],
            (int) $row['chunk_index'],
            (string) $row['type'],
            (int) $row['size'],
            (string) $row['telegram_file_id'],
            $row['telegram_file_unique_id'] === null ? null : (string) $row['telegram_file_unique_id'],
            (string) $row['telegram_chat_id'],
            $row['telegram_message_id'] === null ? null : (int) $row['telegram_message_id'],
        );
    }
}
```

- [ ] **Step 5: Run SQLite init/write/read/delete tests and verify pass**

Run:

```bash
vendor/bin/phpunit tests/Integration/Metadata/SqliteMetadataStoreTest.php
```

Expected: PASS for tests created in this task.

- [ ] **Step 6: Commit SQLite base store**

Run:

```bash
git add src/Metadata/MetadataStore.php src/Metadata/SqliteMetadataStore.php tests/Integration/Metadata/SqliteMetadataStoreTest.php
git commit -m "feat: add SQLite metadata store base operations"
```

---

### Task 6: SQLite move/copy/list/visibility

**Files:**
- Modify: `src/Metadata/SqliteMetadataStore.php`
- Modify: `tests/Integration/Metadata/SqliteMetadataStoreTest.php`

- [ ] **Step 1: Add failing tests for move, copy, visibility, and listing**

Append these test methods to `tests/Integration/Metadata/SqliteMetadataStoreTest.php` before the final class closing brace:

```php
    public function testMoveUpdatesPathAndCascadesChunks(): void
    {
        $this->store->write(
            new FileMetadata('from/big.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('from/big.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->store->move('from/big.bin', 'to/big.bin');

        self::assertNull($this->store->read('from/big.bin'));
        $stored = $this->store->read('to/big.bin');
        self::assertNotNull($stored);
        self::assertSame('to/big.bin', $stored->chunks[0]->path);
    }

    public function testCopyDuplicatesMetadataAndReusesTelegramIds(): void
    {
        $this->store->write(
            new FileMetadata('source.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('source.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->store->copy('source.bin', 'copy.bin');

        $source = $this->store->read('source.bin');
        $copy = $this->store->read('copy.bin');

        self::assertNotNull($source);
        self::assertNotNull($copy);
        self::assertSame('source.bin', $source->metadata->path);
        self::assertSame('copy.bin', $copy->metadata->path);
        self::assertSame('chunk', $copy->chunks[0]->telegramFileId);
        self::assertGreaterThanOrEqual($source->metadata->lastModified, $copy->metadata->lastModified);
    }

    public function testSetVisibilityUpdatesExistingFile(): void
    {
        $this->store->write(new FileMetadata('visible.txt', TelegramType::DOCUMENT, 5, null, Visibility::PRIVATE, 100, 'file', null, '-100', 10));

        $this->store->setVisibility('visible.txt', Visibility::PUBLIC);

        self::assertSame(Visibility::PUBLIC, $this->store->read('visible.txt')?->metadata->visibility);
    }

    public function testListContentsShallowReturnsFilesAndVirtualDirectories(): void
    {
        $this->store->write(new FileMetadata('foo/a.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'a', null, '-100', 1));
        $this->store->write(new FileMetadata('foo/bar/b.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'b', null, '-100', 2));
        $this->store->write(new FileMetadata('foo/bar/c.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'c', null, '-100', 3));
        $this->store->write(new FileMetadata('foo/readme.md', TelegramType::DOCUMENT, 1, null, null, 100, 'r', null, '-100', 4));

        $items = iterator_to_array($this->store->listContents('foo', false));
        $paths = array_map(static fn (object $item): string => $item->path, $items);

        sort($paths);

        self::assertSame(['foo/a.txt', 'foo/bar', 'foo/readme.md'], $paths);
    }

    public function testListContentsDeepReturnsFilesAndVirtualDirectories(): void
    {
        $this->store->write(new FileMetadata('foo/a.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'a', null, '-100', 1));
        $this->store->write(new FileMetadata('foo/bar/b.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'b', null, '-100', 2));
        $this->store->write(new FileMetadata('foo/bar/c.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'c', null, '-100', 3));

        $items = iterator_to_array($this->store->listContents('foo', true));
        $paths = array_map(static fn (object $item): string => $item->path, $items);

        sort($paths);

        self::assertSame(['foo/a.txt', 'foo/bar', 'foo/bar/b.txt', 'foo/bar/c.txt'], $paths);
    }
```

- [ ] **Step 2: Run tests and verify failure**

Run:

```bash
vendor/bin/phpunit tests/Integration/Metadata/SqliteMetadataStoreTest.php
```

Expected: FAIL because move/copy/list/visibility are not implemented.

- [ ] **Step 3: Implement move/copy/setVisibility/listContents**

Modify `src/Metadata/SqliteMetadataStore.php` by replacing the stub methods with:

```php
    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $prefix = trim($path, '/');
            $like = $prefix === '' ? '%' : $prefix . '/%';
            $statement = $this->pdo->prepare('SELECT * FROM files WHERE path LIKE :prefix ORDER BY path ASC');
            $statement->execute(['prefix' => $like]);

            $directories = [];

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $file = $this->hydrateFile($row);
                $relative = $prefix === '' ? $file->path : substr($file->path, strlen($prefix) + 1);

                if ($relative === false || $relative === '') {
                    continue;
                }

                $slashPosition = strpos($relative, '/');

                if ($slashPosition === false) {
                    yield $file;
                    continue;
                }

                $directoryPath = ($prefix === '' ? '' : $prefix . '/') . substr($relative, 0, $slashPosition);

                if (!isset($directories[$directoryPath])) {
                    $directories[$directoryPath] = true;
                    yield new DirectoryMetadata($directoryPath);
                }

                if ($deep) {
                    yield $file;
                }
            }
        } catch (PDOException $exception) {
            throw new MetadataStoreException('Unable to list metadata.', 0, $exception);
        }
    }

    public function move(string $source, string $destination): void
    {
        $this->transaction(function () use ($source, $destination): void {
            $statement = $this->pdo->prepare('UPDATE files SET path = :destination, updated_at = :updated_at WHERE path = :source');
            $statement->execute([
                'destination' => $destination,
                'source' => $source,
                'updated_at' => time(),
            ]);

            if ($statement->rowCount() !== 1) {
                throw new MetadataStoreException(sprintf('Source path "%s" does not exist.', $source));
            }
        });
    }

    public function copy(string $source, string $destination): void
    {
        $this->transaction(function () use ($source, $destination): void {
            $stored = $this->read($source);

            if ($stored === null) {
                throw new MetadataStoreException(sprintf('Source path "%s" does not exist.', $source));
            }

            $now = time();
            $file = new FileMetadata(
                $destination,
                $stored->metadata->type,
                $stored->metadata->size,
                $stored->metadata->mimeType,
                $stored->metadata->visibility,
                $now,
                $stored->metadata->telegramFileId,
                $stored->metadata->telegramFileUniqueId,
                $stored->metadata->telegramChatId,
                $stored->metadata->telegramMessageId,
                $stored->metadata->isChunked,
                $stored->metadata->chunkSize,
                $stored->metadata->chunkCount,
            );

            $chunks = array_map(
                static fn (ChunkMetadata $chunk): ChunkMetadata => new ChunkMetadata(
                    $destination,
                    $chunk->index,
                    $chunk->type,
                    $chunk->size,
                    $chunk->telegramFileId,
                    $chunk->telegramFileUniqueId,
                    $chunk->telegramChatId,
                    $chunk->telegramMessageId,
                ),
                $stored->chunks,
            );

            $this->insertFile($file, $chunks, $now);
        });
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->transaction(function () use ($path, $visibility): void {
            $statement = $this->pdo->prepare('UPDATE files SET visibility = :visibility, updated_at = :updated_at WHERE path = :path');
            $statement->execute([
                'path' => $path,
                'visibility' => $visibility,
                'updated_at' => time(),
            ]);

            if ($statement->rowCount() !== 1) {
                throw new MetadataStoreException(sprintf('Path "%s" does not exist.', $path));
            }
        });
    }
```

Also extract the insert logic from `write()` into this private method so `copy()` can reuse it:

```php
    /**
     * @param list<ChunkMetadata> $chunks
     */
    private function insertFile(FileMetadata $file, array $chunks, int $now): void
    {
        $this->pdo->prepare('INSERT INTO files (path, type, size, mime_type, visibility, last_modified, telegram_file_id, telegram_file_unique_id, telegram_chat_id, telegram_message_id, is_chunked, chunk_size, chunk_count, created_at, updated_at) VALUES (:path, :type, :size, :mime_type, :visibility, :last_modified, :telegram_file_id, :telegram_file_unique_id, :telegram_chat_id, :telegram_message_id, :is_chunked, :chunk_size, :chunk_count, :created_at, :updated_at)')
            ->execute([
                'path' => $file->path,
                'type' => $file->type,
                'size' => $file->size,
                'mime_type' => $file->mimeType,
                'visibility' => $file->visibility,
                'last_modified' => $file->lastModified,
                'telegram_file_id' => $file->telegramFileId,
                'telegram_file_unique_id' => $file->telegramFileUniqueId,
                'telegram_chat_id' => $file->telegramChatId,
                'telegram_message_id' => $file->telegramMessageId,
                'is_chunked' => $file->isChunked ? 1 : 0,
                'chunk_size' => $file->chunkSize,
                'chunk_count' => $file->chunkCount,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        foreach ($chunks as $chunk) {
            $this->pdo->prepare('INSERT INTO chunks (path, chunk_index, type, size, telegram_file_id, telegram_file_unique_id, telegram_chat_id, telegram_message_id, created_at, updated_at) VALUES (:path, :chunk_index, :type, :size, :telegram_file_id, :telegram_file_unique_id, :telegram_chat_id, :telegram_message_id, :created_at, :updated_at)')
                ->execute([
                    'path' => $chunk->path,
                    'chunk_index' => $chunk->index,
                    'type' => $chunk->type,
                    'size' => $chunk->size,
                    'telegram_file_id' => $chunk->telegramFileId,
                    'telegram_file_unique_id' => $chunk->telegramFileUniqueId,
                    'telegram_chat_id' => $chunk->telegramChatId,
                    'telegram_message_id' => $chunk->telegramMessageId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }
```

Then simplify `write()` transaction body to:

```php
        $this->transaction(function () use ($file, $chunks): void {
            $this->pdo->prepare('DELETE FROM chunks WHERE path = :path')->execute(['path' => $file->path]);
            $this->pdo->prepare('DELETE FROM files WHERE path = :path')->execute(['path' => $file->path]);
            $this->insertFile($file, $chunks, time());
        });
```

- [ ] **Step 4: Run SQLite tests and verify pass**

Run:

```bash
vendor/bin/phpunit tests/Integration/Metadata/SqliteMetadataStoreTest.php
```

Expected: PASS.

- [ ] **Step 5: Run all tests**

Run:

```bash
composer test
```

Expected: PASS.

- [ ] **Step 6: Commit complete SQLite store**

Run:

```bash
git add src/Metadata/SqliteMetadataStore.php tests/Integration/Metadata/SqliteMetadataStoreTest.php
git commit -m "feat: complete SQLite metadata operations"
```

---

### Task 7: Telegram client with Guzzle

**Files:**
- Create: `src/Telegram/TelegramClientInterface.php`
- Create: `src/Telegram/TelegramUploadRequest.php`
- Create: `src/Telegram/TelegramUploadedFile.php`
- Create: `src/Telegram/GuzzleTelegramClient.php`
- Create: `tests/Integration/Telegram/GuzzleTelegramClientTest.php`

- [ ] **Step 1: Write failing Guzzle client tests**

Create `tests/Integration/Telegram/GuzzleTelegramClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Integration\Telegram;

use Aahl\FlysystemTelegram\Exception\TelegramApiException;
use Aahl\FlysystemTelegram\Telegram\GuzzleTelegramClient;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GuzzleTelegramClientTest extends TestCase
{
    public function testUploadMapsTypeToBotApiMethodAndParsesResponse(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode([
                'ok' => true,
                'result' => [
                    'message_id' => 42,
                    'chat' => ['id' => -100],
                    'document' => [
                        'file_id' => 'file-id',
                        'file_unique_id' => 'unique-id',
                        'file_size' => 5,
                        'mime_type' => 'text/plain',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $uploaded = $client->upload(new TelegramUploadRequest(TelegramType::DOCUMENT, '-100', 'hello', 'a.txt', 'text/plain'));

        self::assertSame('file-id', $uploaded->fileId);
        self::assertSame('unique-id', $uploaded->fileUniqueId);
        self::assertSame('-100', $uploaded->chatId);
        self::assertSame(42, $uploaded->messageId);
        self::assertSame(5, $uploaded->size);
        self::assertSame('text/plain', $uploaded->mimeType);
        self::assertSame('/botTOKEN/sendDocument', $history[0]['request']->getUri()->getPath());
        $body = (string) $history[0]['request']->getBody();
        self::assertStringContainsString('name="chat_id"', $body);
        self::assertStringContainsString('-100', $body);
        self::assertStringContainsString('name="document"', $body);
        self::assertStringContainsString('filename="a.txt"', $body);
    }

    public function testTypedUploadsMapToExpectedBotApiMethods(): void
    {
        $cases = [
            [TelegramType::PHOTO, '/botTOKEN/sendPhoto', $this->photoResponse()],
            [TelegramType::VIDEO, '/botTOKEN/sendVideo', $this->typedResponse('video')],
            [TelegramType::AUDIO, '/botTOKEN/sendAudio', $this->typedResponse('audio')],
            [TelegramType::ANIMATION, '/botTOKEN/sendAnimation', $this->typedResponse('animation')],
        ];

        foreach ($cases as [$type, $path, $response]) {
            $history = [];
            $client = $this->client([$response], $history);

            $client->upload(new TelegramUploadRequest($type, '-100', 'bytes', 'file.bin', null));

            self::assertSame($path, $history[0]['request']->getUri()->getPath());
            self::assertStringContainsString('name="' . $type . '"', (string) $history[0]['request']->getBody());
        }
    }

    public function testDownloadStreamUsesGetFileThenFileEndpoint(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode(['ok' => true, 'result' => ['file_path' => 'documents/file.txt']], JSON_THROW_ON_ERROR)),
            new Response(200, [], 'content'),
        ], $history);

        $stream = $client->downloadStream('file-id');

        self::assertIsResource($stream);
        self::assertSame('content', stream_get_contents($stream));
        self::assertSame('/botTOKEN/getFile', $history[0]['request']->getUri()->getPath());
        self::assertSame('/file/botTOKEN/documents/file.txt', $history[1]['request']->getUri()->getPath());
    }

    public function testTelegramOkFalseThrowsException(): void
    {
        $this->expectException(TelegramApiException::class);
        $this->expectExceptionMessage('Telegram API error: bad request');

        $client = $this->client([
            new Response(200, [], json_encode(['ok' => false, 'description' => 'bad request'], JSON_THROW_ON_ERROR)),
        ]);

        $client->upload(new TelegramUploadRequest(TelegramType::DOCUMENT, '-100', 'hello', 'a.txt', 'text/plain'));
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history = []): GuzzleTelegramClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new GuzzleTelegramClient(
            'TOKEN',
            new Client(['handler' => $stack]),
            'https://api.telegram.org',
            'https://api.telegram.org/file',
            30.0,
        );
    }

    private function photoResponse(): Response
    {
        return new Response(200, [], json_encode([
            'ok' => true,
            'result' => [
                'message_id' => 43,
                'chat' => ['id' => -100],
                'photo' => [[
                    'file_id' => 'photo-file',
                    'file_unique_id' => 'photo-unique',
                    'file_size' => 5,
                ]],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function typedResponse(string $key): Response
    {
        return new Response(200, [], json_encode([
            'ok' => true,
            'result' => [
                'message_id' => 44,
                'chat' => ['id' => -100],
                $key => [
                    'file_id' => $key . '-file',
                    'file_unique_id' => $key . '-unique',
                    'file_size' => 5,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
```

- [ ] **Step 2: Run Guzzle client tests and verify failure**

Run:

```bash
vendor/bin/phpunit tests/Integration/Telegram/GuzzleTelegramClientTest.php
```

Expected: FAIL because Telegram client classes do not exist.

- [ ] **Step 3: Add Telegram DTOs and interface**

Create `src/Telegram/TelegramClientInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Telegram;

interface TelegramClientInterface
{
    public function upload(TelegramUploadRequest $request): TelegramUploadedFile;

    public function downloadStream(string $fileId): mixed;
}
```

Create `src/Telegram/TelegramUploadRequest.php`:

```php
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
```

Create `src/Telegram/TelegramUploadedFile.php`:

```php
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
```

- [ ] **Step 4: Add Guzzle Telegram client**

Create `src/Telegram/GuzzleTelegramClient.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Telegram;

use Aahl\FlysystemTelegram\Exception\TelegramApiException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

final class GuzzleTelegramClient implements TelegramClientInterface
{
    private ClientInterface $httpClient;

    /**
     * @var array<string, array{method: string, field: string, response_key: string}>
     */
    private const TYPE_MAP = [
        TelegramType::PHOTO => ['method' => 'sendPhoto', 'field' => 'photo', 'response_key' => 'photo'],
        TelegramType::VIDEO => ['method' => 'sendVideo', 'field' => 'video', 'response_key' => 'video'],
        TelegramType::AUDIO => ['method' => 'sendAudio', 'field' => 'audio', 'response_key' => 'audio'],
        TelegramType::ANIMATION => ['method' => 'sendAnimation', 'field' => 'animation', 'response_key' => 'animation'],
        TelegramType::DOCUMENT => ['method' => 'sendDocument', 'field' => 'document', 'response_key' => 'document'],
    ];

    public function __construct(
        private readonly string $botToken,
        ?ClientInterface $httpClient = null,
        private readonly string $apiBaseUri = 'https://api.telegram.org',
        private readonly float $timeout = 30.0,
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    public function upload(TelegramUploadRequest $request): TelegramUploadedFile
    {
        TelegramType::assertValid($request->type);
        $mapping = self::TYPE_MAP[$request->type];

        $multipart = [
            ['name' => 'chat_id', 'contents' => $request->chatId],
            ['name' => $mapping['field'], 'contents' => $request->contents, 'filename' => $request->filename ?? basename($mapping['field'])],
        ];

        if ($request->mimeType !== null) {
            $multipart[] = ['name' => 'mime_type', 'contents' => $request->mimeType];
        }

        if ($request->replyToMessageId !== null) {
            $multipart[] = ['name' => 'reply_to_message_id', 'contents' => (string) $request->replyToMessageId];
        }

        $payload = $this->requestJson('POST', $this->apiUrl($mapping['method']), ['multipart' => $multipart]);
        $result = $payload['result'];
        $file = $this->extractUploadedFile($request->type, $result, $mapping['response_key']);

        return new TelegramUploadedFile(
            $request->type,
            (string) $file['file_id'],
            isset($file['file_unique_id']) ? (string) $file['file_unique_id'] : null,
            (string) $request->chatId,
            isset($result['message_id']) ? (int) $result['message_id'] : null,
            isset($file['file_size']) ? (int) $file['file_size'] : null,
            isset($file['mime_type']) ? (string) $file['mime_type'] : $request->mimeType,
        );
    }

    public function downloadStream(string $fileId): mixed
    {
        $payload = $this->requestJson('POST', $this->apiUrl('getFile'), [
            'form_params' => ['file_id' => $fileId],
        ]);

        if (!isset($payload['result']['file_path'])) {
            throw new TelegramApiException('Telegram getFile response did not include file_path.');
        }

        try {
            $response = $this->httpClient->request('GET', $this->fileUrl((string) $payload['result']['file_path']), [
                'stream' => true,
                'timeout' => $this->timeout,
            ]);
        } catch (GuzzleException $exception) {
            throw new TelegramApiException('Telegram file download failed.', 0, $exception);
        }

        return $response->getBody()->detach();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options + ['timeout' => $this->timeout]);
        } catch (GuzzleException $exception) {
            throw new TelegramApiException('Telegram API request failed.', 0, $exception);
        }

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true);

        if (!is_array($payload)) {
            throw new TelegramApiException('Telegram API returned invalid JSON.');
        }

        if (($payload['ok'] ?? false) !== true) {
            $description = isset($payload['description']) ? (string) $payload['description'] : 'unknown error';
            throw new TelegramApiException('Telegram API error: ' . $description);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function extractUploadedFile(string $type, array $result, string $responseKey): array
    {
        if ($type === TelegramType::PHOTO) {
            $photos = $result[$responseKey] ?? null;

            if (!is_array($photos) || $photos === []) {
                throw new TelegramApiException('Telegram photo response did not include photo sizes.');
            }

            $last = end($photos);

            if (!is_array($last)) {
                throw new TelegramApiException('Telegram photo response was malformed.');
            }

            return $last;
        }

        $file = $result[$responseKey] ?? null;

        if (!is_array($file)) {
            throw new TelegramApiException(sprintf('Telegram %s response did not include uploaded file.', $type));
        }

        return $file;
    }

    private function apiUrl(string $method): string
    {
        return rtrim($this->apiBaseUri, '/') . '/bot' . $this->botToken . '/' . $method;
    }

    private function fileUrl(string $filePath): string
    {
        return rtrim($this->apiBaseUri, '/') . '/file/bot' . $this->botToken . '/' . ltrim($filePath, '/');
    }
}
```

- [ ] **Step 5: Run Guzzle client tests and verify pass**

Run:

```bash
vendor/bin/phpunit tests/Integration/Telegram/GuzzleTelegramClientTest.php
```

Expected: PASS.

- [ ] **Step 6: Run all tests**

Run:

```bash
composer test
```

Expected: PASS.

- [ ] **Step 7: Commit Telegram client**

Run:

```bash
git add src/Telegram tests/Integration/Telegram
git commit -m "feat: add Guzzle Telegram client"
```

---

### Task 8: Lazy chunked stream

**Files:**
- Create: `src/Stream/ChunkedReadStreamRegistry.php`
- Create: `src/Stream/ChunkedReadStreamWrapper.php`
- Create: `src/Stream/ChunkedReadStream.php`
- Create: `tests/Fake/FakeTelegramClient.php`
- Create: `tests/Unit/Stream/ChunkedReadStreamTest.php`

- [ ] **Step 1: Create fake Telegram client for stream tests**

Create `tests/Fake/FakeTelegramClient.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Fake;

use Aahl\FlysystemTelegram\Telegram\TelegramClientInterface;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadedFile;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadRequest;
use RuntimeException;

final class FakeTelegramClient implements TelegramClientInterface
{
    /**
     * @param array<string, string> $downloads
     * @param list<string> $uploadFailures
     */
    public function __construct(
        public array $downloads = [],
        public ?string $failDownloadFor = null,
        public array $uploadFailures = [],
    ) {
    }

    /** @var list<string> */
    public array $downloadedFileIds = [];

    /** @var list<string> */
    public array $uploadedTypes = [];

    public function upload(TelegramUploadRequest $request): TelegramUploadedFile
    {
        $this->uploadedTypes[] = $request->type;

        if (($this->uploadFailures[0] ?? null) === $request->type) {
            array_shift($this->uploadFailures);
            throw new RuntimeException('upload failed for ' . $request->type);
        }

        return new TelegramUploadedFile($request->type, 'uploaded-file-id-' . count($this->uploadedTypes), null, $request->chatId, count($this->uploadedTypes), null, $request->mimeType);
    }

    public function downloadStream(string $fileId): mixed
    {
        $this->downloadedFileIds[] = $fileId;

        if ($this->failDownloadFor === $fileId) {
            throw new RuntimeException('download failed for ' . $fileId);
        }

        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to create fake stream.');
        }

        fwrite($stream, $this->downloads[$fileId] ?? '');
        rewind($stream);

        return $stream;
    }
}
```

- [ ] **Step 2: Write failing lazy stream tests**

Create `tests/Unit/Stream/ChunkedReadStreamTest.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Unit\Stream;

use Aahl\FlysystemTelegram\Exception\ChunkedStreamException;
use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Stream\ChunkedReadStream;
use Aahl\FlysystemTelegram\Stream\ChunkedReadStreamRegistry;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\Tests\Fake\FakeTelegramClient;
use PHPUnit\Framework\TestCase;

final class ChunkedReadStreamTest extends TestCase
{
    public function testChunkDownloadsAreLazy(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('flysystem-telegram-test-a', $client, $this->storedFile());

        self::assertIsResource($stream);
        self::assertSame([], $client->downloadedFileIds);
        self::assertSame('abc', fread($stream, 3));
        self::assertSame(['file-0'], $client->downloadedFileIds);
        self::assertSame('def', fread($stream, 3));
        self::assertSame(['file-0', 'file-1'], $client->downloadedFileIds);

        fclose($stream);
    }

    public function testStreamGetContentsConcatenatesChunks(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('flysystem-telegram-test-b', $client, $this->storedFile());

        self::assertSame('abcdef', stream_get_contents($stream));

        fclose($stream);
    }

    public function testCloseReleasesRegistryToken(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('flysystem-telegram-test-c', $client, $this->storedFile());
        $countBeforeClose = ChunkedReadStreamRegistry::count();

        fclose($stream);

        self::assertSame($countBeforeClose - 1, ChunkedReadStreamRegistry::count());
    }

    public function testOpenFailureReleasesRegistryToken(): void
    {
        $protocol = 'flysystem-telegram-open-fail-test';
        stream_wrapper_register($protocol, FailingOpenStreamWrapper::class);
        $registeredProtocols = new \ReflectionProperty(ChunkedReadStream::class, 'registeredProtocols');
        $registeredProtocols->setValue(null, [$protocol => true]);
        $countBeforeOpen = ChunkedReadStreamRegistry::count();

        try {
            $this->expectException(ChunkedStreamException::class);
            ChunkedReadStream::open($protocol, new FakeTelegramClient(), $this->storedFile());
        } finally {
            self::assertSame($countBeforeOpen, ChunkedReadStreamRegistry::count());
            $registeredProtocols->setValue(null, []);
            stream_wrapper_unregister($protocol);
        }
    }

    public function testDownloadFailureThrowsChunkedStreamException(): void
    {
        $this->expectException(ChunkedStreamException::class);

        $client = new FakeTelegramClient(['file-0' => 'abc'], 'file-0');
        $stream = ChunkedReadStream::open('flysystem-telegram-test-d', $client, $this->storedFile());

        fread($stream, 3);
    }

    public function testInvalidProtocolThrowsException(): void
    {
        $this->expectException(ChunkedStreamException::class);
        $this->expectExceptionMessage('Invalid stream protocol');

        ChunkedReadStream::open('1-invalid', new FakeTelegramClient(), $this->storedFile());
    }

    public function testCustomProtocolWorks(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('custom-telegram-fs-test', $client, $this->storedFile());

        self::assertSame('abcdef', stream_get_contents($stream));

        fclose($stream);
    }

    public function testExternalProtocolConflictThrowsException(): void
    {
        $protocol = 'flysystem-telegram-conflict-test';
        stream_wrapper_register($protocol, DummyConflictStreamWrapper::class);

        try {
            $this->expectException(ChunkedStreamException::class);
            $this->expectExceptionMessage('already registered');

            ChunkedReadStream::open($protocol, new FakeTelegramClient(), $this->storedFile());
        } finally {
            stream_wrapper_unregister($protocol);
        }
    }

    public function testChunkedStreamIsNotSeekable(): void
    {
        $client = new FakeTelegramClient(['file-0' => 'abc', 'file-1' => 'def']);
        $stream = ChunkedReadStream::open('flysystem-telegram-test-seek', $client, $this->storedFile());

        self::assertFalse(fseek($stream, 1) === 0);

        fclose($stream);
    }

    private function storedFile(): StoredFile
    {
        return new StoredFile(
            new FileMetadata('big.bin', TelegramType::DOCUMENT, 6, null, 'private', 100, null, null, '-100', null, true, 3, 2),
            [
                new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 3, 'file-0', null, '-100', 1),
                new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 3, 'file-1', null, '-100', 2),
            ],
        );
    }
}

final class DummyConflictStreamWrapper
{
    public mixed $context;
}

final class FailingOpenStreamWrapper
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}
```

- [ ] **Step 3: Run lazy stream tests and verify failure**

Run:

```bash
vendor/bin/phpunit tests/Unit/Stream/ChunkedReadStreamTest.php
```

Expected: FAIL because stream classes do not exist.

- [ ] **Step 4: Add registry**

Create `src/Stream/ChunkedReadStreamRegistry.php`:

```php
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
```

- [ ] **Step 5: Add stream wrapper**

Create `src/Stream/ChunkedReadStreamWrapper.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Stream;

use Aahl\FlysystemTelegram\Exception\ChunkedStreamException;
use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Telegram\TelegramClientInterface;
use Throwable;

final class ChunkedReadStreamWrapper
{
    public mixed $context;
    private ?string $token = null;
    private ?TelegramClientInterface $client = null;
    private ?StoredFile $file = null;
    private int $chunkIndex = 0;
    private mixed $currentStream = null;
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $token = basename(parse_url($path, PHP_URL_PATH) ?: '');
        $entry = ChunkedReadStreamRegistry::take($token);

        if ($entry === null) {
            return false;
        }

        $this->token = $token;
        $this->client = $entry['client'];
        $this->file = $entry['file'];

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->client === null || $this->file === null) {
            throw new ChunkedStreamException('Chunked stream is not open.');
        }

        $buffer = '';

        while (strlen($buffer) < $count && !$this->stream_eof()) {
            $this->openCurrentChunkIfNeeded();

            if (!is_resource($this->currentStream)) {
                break;
            }

            $data = fread($this->currentStream, $count - strlen($buffer));

            if ($data === false) {
                throw new ChunkedStreamException('Unable to read chunk stream.');
            }

            if ($data === '' && feof($this->currentStream)) {
                fclose($this->currentStream);
                $this->currentStream = null;
                $this->chunkIndex++;
                continue;
            }

            $buffer .= $data;
            $this->position += strlen($data);
        }

        return $buffer;
    }

    public function stream_eof(): bool
    {
        if ($this->file === null) {
            return true;
        }

        return $this->chunkIndex >= count($this->file->chunks) && !is_resource($this->currentStream);
    }

    public function stream_close(): void
    {
        if (is_resource($this->currentStream)) {
            fclose($this->currentStream);
        }

        if ($this->token !== null) {
            ChunkedReadStreamRegistry::release($this->token);
        }
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    private function openCurrentChunkIfNeeded(): void
    {
        if (is_resource($this->currentStream)) {
            return;
        }

        if ($this->client === null || $this->file === null || !isset($this->file->chunks[$this->chunkIndex])) {
            return;
        }

        $chunk = $this->file->chunks[$this->chunkIndex];

        try {
            $stream = $this->client->downloadStream($chunk->telegramFileId);
        } catch (Throwable $exception) {
            throw new ChunkedStreamException('Unable to download chunk ' . $chunk->index . '.', 0, $exception);
        }

        if (!is_resource($stream)) {
            throw new ChunkedStreamException('Telegram client did not return a stream for chunk ' . $chunk->index . '.');
        }

        $this->currentStream = $stream;
    }
}
```

- [ ] **Step 6: Add ChunkedReadStream facade**

Create `src/Stream/ChunkedReadStream.php`:

```php
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
        $stream = fopen($protocol . '://chunked/' . $token, 'rb');

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
```

- [ ] **Step 7: Run lazy stream tests and verify pass**

Run:

```bash
vendor/bin/phpunit tests/Unit/Stream/ChunkedReadStreamTest.php
```

Expected: PASS.

- [ ] **Step 8: Run all tests**

Run:

```bash
composer test
```

Expected: PASS.

- [ ] **Step 9: Commit lazy chunk stream**

Run:

```bash
git add src/Stream tests/Fake/FakeTelegramClient.php tests/Unit/Stream
git commit -m "feat: add lazy chunk read stream"
```

---

### Task 9: TelegramAdapter read/write/delete/move/copy/metadata

**Files:**
- Create: `src/TelegramAdapter.php`
- Create: `tests/Fake/FakeMetadataStore.php`
- Create: `tests/Unit/TelegramAdapterTest.php`

- [ ] **Step 1: Create fake metadata store**

Create `tests/Fake/FakeMetadataStore.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Fake;

use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\DirectoryMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use RuntimeException;

final class FakeMetadataStore implements MetadataStore
{
    /** @var array<string, StoredFile> */
    public array $files = [];

    public bool $failWrites = false;

    public function write(FileMetadata $file, array $chunks = []): void
    {
        if ($this->failWrites) {
            throw new RuntimeException('metadata write failed');
        }

        $this->files[$file->path] = new StoredFile($file, $chunks);
    }

    public function read(string $path): ?StoredFile
    {
        return $this->files[$path] ?? null;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function fileExists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        foreach ($this->files as $storedFile) {
            yield $storedFile->metadata;
        }
    }

    public function move(string $source, string $destination): void
    {
        $stored = $this->files[$source];
        unset($this->files[$source]);
        $metadata = $stored->metadata;
        $this->files[$destination] = new StoredFile(
            new FileMetadata($destination, $metadata->type, $metadata->size, $metadata->mimeType, $metadata->visibility, time(), $metadata->telegramFileId, $metadata->telegramFileUniqueId, $metadata->telegramChatId, $metadata->telegramMessageId, $metadata->isChunked, $metadata->chunkSize, $metadata->chunkCount),
            array_map(static fn (ChunkMetadata $chunk): ChunkMetadata => new ChunkMetadata($destination, $chunk->index, $chunk->type, $chunk->size, $chunk->telegramFileId, $chunk->telegramFileUniqueId, $chunk->telegramChatId, $chunk->telegramMessageId), $stored->chunks),
        );
    }

    public function copy(string $source, string $destination): void
    {
        $stored = $this->files[$source];
        $metadata = $stored->metadata;
        $this->files[$destination] = new StoredFile(
            new FileMetadata($destination, $metadata->type, $metadata->size, $metadata->mimeType, $metadata->visibility, time(), $metadata->telegramFileId, $metadata->telegramFileUniqueId, $metadata->telegramChatId, $metadata->telegramMessageId, $metadata->isChunked, $metadata->chunkSize, $metadata->chunkCount),
            array_map(static fn (ChunkMetadata $chunk): ChunkMetadata => new ChunkMetadata($destination, $chunk->index, $chunk->type, $chunk->size, $chunk->telegramFileId, $chunk->telegramFileUniqueId, $chunk->telegramChatId, $chunk->telegramMessageId), $stored->chunks),
        );
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $stored = $this->files[$path];
        $metadata = $stored->metadata;
        $this->files[$path] = new StoredFile(
            new FileMetadata($metadata->path, $metadata->type, $metadata->size, $metadata->mimeType, $visibility, $metadata->lastModified, $metadata->telegramFileId, $metadata->telegramFileUniqueId, $metadata->telegramChatId, $metadata->telegramMessageId, $metadata->isChunked, $metadata->chunkSize, $metadata->chunkCount),
            $stored->chunks,
        );
    }
}
```

- [ ] **Step 2: Write failing adapter tests**

Create `tests/Unit/TelegramAdapterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Unit;

use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\TelegramAdapter;
use Aahl\FlysystemTelegram\Tests\Fake\FakeMetadataStore;
use Aahl\FlysystemTelegram\Tests\Fake\FakeTelegramClient;
use League\Flysystem\Config;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

final class TelegramAdapterTest extends TestCase
{
    public function testWriteStoresUploadedMetadata(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient();
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), $telegramClient, $metadataStore);

        $adapter->write('docs/a.txt', 'hello', new Config(['mime_type' => 'text/plain', 'visibility' => Visibility::PRIVATE]));

        $stored = $metadataStore->read('docs/a.txt');
        self::assertNotNull($stored);
        self::assertSame(TelegramType::DOCUMENT, $stored->metadata->type);
        self::assertSame(5, $stored->metadata->size);
        self::assertSame(Visibility::PRIVATE, $stored->metadata->visibility);
        self::assertSame('uploaded-file-id-1', $stored->metadata->telegramFileId);
    }

    public function testMetadataWriteFailureDoesNotTriggerChunkedFallback(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->failWrites = true;
        $telegramClient = new FakeTelegramClient();
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), $telegramClient, $metadataStore);

        try {
            $adapter->write('docs/a.txt', 'hello', new Config(['mime_type' => 'text/plain']));
            self::fail('Expected write to fail.');
        } catch (\League\Flysystem\UnableToWriteFile) {
            self::assertSame([TelegramType::DOCUMENT], $telegramClient->uploadedTypes);
        }
    }

    public function testReadDownloadsStoredFile(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, 'text/plain', Visibility::PRIVATE, 100, 'file-id', null, '-100', 1));
        $telegramClient = new FakeTelegramClient(['file-id' => 'hello']);
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), $telegramClient, $metadataStore);

        self::assertSame('hello', $adapter->read('docs/a.txt'));
    }

    public function testTypedUploadFailureFallsBackToDocument(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient(uploadFailures: [TelegramType::PHOTO]);
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), $telegramClient, $metadataStore);

        $adapter->write('photo.jpg', 'hello', new Config(['mime_type' => 'image/jpeg']));

        self::assertSame([TelegramType::PHOTO, TelegramType::DOCUMENT], $telegramClient->uploadedTypes);
        self::assertSame(TelegramType::DOCUMENT, $metadataStore->read('photo.jpg')?->metadata->type);
    }

    public function testDocumentUploadFailureFallsBackToChunkedDocumentWhenEnabled(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient(uploadFailures: [TelegramType::PHOTO, TelegramType::DOCUMENT]);
        $adapter = new TelegramAdapter(
            new TelegramAdapterConfig(
                botToken: 'token',
                chatId: '-100',
                chunkSize: 3,
                typeSizeLimits: [TelegramType::PHOTO => 100, TelegramType::DOCUMENT => 100],
            ),
            $telegramClient,
            $metadataStore,
        );

        $adapter->write('photo.jpg', 'hello', new Config(['mime_type' => 'image/jpeg']));

        self::assertSame([TelegramType::PHOTO, TelegramType::DOCUMENT, TelegramType::DOCUMENT, TelegramType::DOCUMENT], $telegramClient->uploadedTypes);
        $stored = $metadataStore->read('photo.jpg');
        self::assertNotNull($stored);
        self::assertTrue($stored->isChunked());
        self::assertSame(2, $stored->metadata->chunkCount);
    }

    public function testDocumentUploadFailureWithChunkingDisabledThrowsWriteException(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient(uploadFailures: [TelegramType::DOCUMENT]);
        $adapter = new TelegramAdapter(
            new TelegramAdapterConfig(
                botToken: 'token',
                chatId: '-100',
                enableChunking: false,
            ),
            $telegramClient,
            $metadataStore,
        );

        $this->expectException(\League\Flysystem\UnableToWriteFile::class);

        $adapter->write('archive.zip', 'hello', new Config(['mime_type' => 'application/zip']));
    }

    public function testChunkedWriteUsesEffectiveChunkSizeFromUploadStrategy(): void
    {
        $metadataStore = new FakeMetadataStore();
        $telegramClient = new FakeTelegramClient();
        $adapter = new TelegramAdapter(
            new TelegramAdapterConfig(
                botToken: 'token',
                chatId: '-100',
                chunkSize: 5000,
                typeSizeLimits: [TelegramType::DOCUMENT => 3],
            ),
            $telegramClient,
            $metadataStore,
        );

        $adapter->write('big.bin', 'abcdefg', new Config());

        $stored = $metadataStore->read('big.bin');
        self::assertNotNull($stored);
        self::assertTrue($stored->isChunked());
        self::assertSame(3, $stored->metadata->chunkSize);
        self::assertSame(3, $stored->metadata->chunkCount);
    }

    public function testReadMissingFileThrowsFlysystemException(): void
    {
        $this->expectException(UnableToReadFile::class);

        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), new FakeMetadataStore());

        $adapter->read('missing.txt');
    }

    public function testDeleteRemovesMetadataOnly(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, null, null, 100, 'file-id', null, '-100', 1));
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(['file-id' => 'hello']), $metadataStore);

        $adapter->delete('docs/a.txt');

        self::assertFalse($metadataStore->fileExists('docs/a.txt'));
    }

    public function testMoveCopyAndVisibilityDelegateToMetadataStore(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->write(new FileMetadata('source.txt', TelegramType::DOCUMENT, 5, null, Visibility::PRIVATE, 100, 'file-id', null, '-100', 1));
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), $metadataStore);

        $adapter->copy('source.txt', 'copy.txt', new Config());
        $adapter->move('copy.txt', 'moved.txt', new Config());
        $adapter->setVisibility('moved.txt', Visibility::PUBLIC);

        self::assertTrue($metadataStore->fileExists('source.txt'));
        self::assertTrue($metadataStore->fileExists('moved.txt'));
        self::assertSame(Visibility::PUBLIC, $metadataStore->read('moved.txt')?->metadata->visibility);
    }

    public function testMetadataMethodsReturnFlysystemAttributes(): void
    {
        $metadataStore = new FakeMetadataStore();
        $metadataStore->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, 'text/plain', Visibility::PRIVATE, 123, 'file-id', null, '-100', 1));
        $adapter = new TelegramAdapter(new TelegramAdapterConfig(botToken: 'token', chatId: '-100'), new FakeTelegramClient(), $metadataStore);

        self::assertTrue($adapter->fileExists('docs/a.txt'));
        self::assertSame(5, $adapter->fileSize('docs/a.txt')->fileSize());
        self::assertSame('text/plain', $adapter->mimeType('docs/a.txt')->mimeType());
        self::assertSame(123, $adapter->lastModified('docs/a.txt')->lastModified());
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('docs/a.txt')->visibility());
    }
}
```

- [ ] **Step 3: Run adapter tests and verify failure**

Run:

```bash
vendor/bin/phpunit tests/Unit/TelegramAdapterTest.php
```

Expected: FAIL because `TelegramAdapter` does not exist.

- [ ] **Step 4: Verify Flysystem adapter method signatures**

Run:

```bash
php -r "require 'vendor/autoload.php'; $ref = new ReflectionClass(League\\Flysystem\\FilesystemAdapter::class); foreach ($ref->getMethods() as $method) { echo $method->getName() . '('; echo implode(', ', array_map(fn($p) => ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName(), $method->getParameters())); echo '): ' . ($method->hasReturnType() ? $method->getReturnType() : 'mixed') . PHP_EOL; }"
```

Expected: Output confirms the exact signatures for `fileExists`, `directoryExists`, `write`, `writeStream`, `read`, `readStream`, `delete`, `deleteDirectory`, `createDirectory`, `setVisibility`, `visibility`, `mimeType`, `lastModified`, `fileSize`, `listContents`, `move`, and `copy`. If the installed Flysystem version differs from the method signatures in this task, update only the signatures to match the reflection output before continuing.

- [ ] **Step 5: Implement TelegramAdapter**

Create `src/TelegramAdapter.php`:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram;

use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\DirectoryMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\Metadata\SqliteMetadataStore;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Stream\ChunkedReadStream;
use Aahl\FlysystemTelegram\Telegram\GuzzleTelegramClient;
use Aahl\FlysystemTelegram\Telegram\TelegramClientInterface;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadedFile;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadRequest;
use Aahl\FlysystemTelegram\Upload\UploadStrategyResolver;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Throwable;

final class TelegramAdapter implements FilesystemAdapter
{
    private TelegramClientInterface $telegramClient;
    private MetadataStore $metadataStore;
    private ChunkManager $chunkManager;
    private UploadStrategyResolver $uploadStrategyResolver;

    public function __construct(
        private readonly TelegramAdapterConfig $config,
        ?TelegramClientInterface $telegramClient = null,
        ?MetadataStore $metadataStore = null,
        ?ChunkManager $chunkManager = null,
        ?UploadStrategyResolver $uploadStrategyResolver = null,
    ) {
        $this->telegramClient = $telegramClient ?? new GuzzleTelegramClient($config->resolveBotToken(), null, $config->apiBaseUri, $config->timeout);
        $this->metadataStore = $metadataStore ?? new SqliteMetadataStore($config->resolveDatabasePath());
        $this->chunkManager = $chunkManager ?? new ChunkManager($config->maxFileSize, $config->chunkSize);
        $this->uploadStrategyResolver = $uploadStrategyResolver ?? new UploadStrategyResolver();
    }

    public function fileExists(string $path): bool
    {
        return $this->metadataStore->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        foreach ($this->metadataStore->listContents($path, true) as $item) {
            return true;
        }

        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw UnableToWriteFile::atLocation($path, 'Unable to create temporary stream.');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $size = $this->streamSize($contents);
            $mimeType = $this->uploadStrategyResolver->resolveMimeType($path, $config->get('mime_type'));
            $strategy = $this->uploadStrategyResolver->resolve($path, $mimeType, $size, $this->config);
            $visibility = $config->get('visibility') ?? $this->config->defaultVisibility;
            $chatId = $this->config->resolveChatId();
            $now = time();

            if ($strategy->chunked) {
                $this->writeChunkedDocument($path, $contents, $mimeType, $visibility, $chatId, $size, $strategy->chunkSize, $now);
                return;
            }

            try {
                $uploaded = $this->uploadWithFallback($path, $contents, $mimeType, $strategy->type, $chatId);
            } catch (Throwable $uploadException) {
                if (!$this->config->enableChunking) {
                    throw $uploadException;
                }

                $this->rewindStream($contents);

                $this->writeChunkedDocument($path, $contents, $mimeType, $visibility, $chatId, $size, $strategy->chunkSize, $now);
                return;
            }

            $file = new FileMetadata($path, $uploaded->type, $size, $mimeType, $visibility, $now, $uploaded->fileId, $uploaded->fileUniqueId, $uploaded->chatId, $uploaded->messageId);
            $this->metadataStore->write($file);
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            $contents = stream_get_contents($stream);

            if ($contents === false) {
                throw UnableToReadFile::fromLocation($path);
            }

            return $contents;
        } catch (UnableToReadFile $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $path)
    {
        try {
            $stored = $this->metadataStore->read($path);

            if ($stored === null) {
                throw UnableToReadFile::fromLocation($path);
            }

            if ($stored->isChunked()) {
                $this->chunkManager->validateStoredFile($stored);
                return ChunkedReadStream::open($this->config->chunkStreamProtocol, $this->telegramClient, $stored);
            }

            if ($stored->metadata->telegramFileId === null) {
                throw UnableToReadFile::fromLocation($path, 'Stored file does not have a Telegram file id.');
            }

            return $this->telegramClient->downloadStream($stored->metadata->telegramFileId);
        } catch (UnableToReadFile $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->metadataStore->delete($path);
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        foreach ($this->metadataStore->listContents($path, true) as $item) {
            if ($item instanceof FileMetadata) {
                $this->delete($item->path);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
    }

    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $this->metadataStore->setVisibility($path, $visibility);
        } catch (Throwable $exception) {
            throw UnableToSetVisibility::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $stored = $this->metadataOrFail($path, 'visibility');

        return new FileAttributes($path, null, $stored->metadata->visibility ?? $this->config->defaultVisibility);
    }

    public function mimeType(string $path): FileAttributes
    {
        $stored = $this->metadataOrFail($path, 'mime type');

        return new FileAttributes($path, null, null, null, $stored->metadata->mimeType);
    }

    public function lastModified(string $path): FileAttributes
    {
        $stored = $this->metadataOrFail($path, 'last modified');

        return new FileAttributes($path, null, null, $stored->metadata->lastModified);
    }

    public function fileSize(string $path): FileAttributes
    {
        $stored = $this->metadataOrFail($path, 'file size');

        return new FileAttributes($path, $stored->metadata->size);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        foreach ($this->metadataStore->listContents($path, $deep) as $item) {
            if ($item instanceof DirectoryMetadata) {
                yield new DirectoryAttributes($item->path);
                continue;
            }

            yield new FileAttributes($item->path, $item->size, $item->visibility, $item->lastModified, $item->mimeType);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->metadataStore->move($source, $destination);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->metadataStore->copy($source, $destination);
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    private function uploadWithFallback(string $path, mixed $contents, ?string $mimeType, string $type, string $chatId): TelegramUploadedFile
    {
        try {
            return $this->telegramClient->upload(new TelegramUploadRequest($type, $chatId, $contents, basename($path), $mimeType));
        } catch (Throwable $exception) {
            if ($type === TelegramType::DOCUMENT) {
                throw $exception;
            }

            $this->rewindStream($contents);

            return $this->telegramClient->upload(new TelegramUploadRequest(TelegramType::DOCUMENT, $chatId, $contents, basename($path), $mimeType));
        }
    }

    private function writeChunkedDocument(string $path, mixed $contents, ?string $mimeType, string $visibility, string $chatId, int $size, int $chunkSize, int $now): void
    {
        $chunks = [];
        $index = 0;

        foreach ($this->chunkManager->splitStream($contents, $chunkSize) as $part) {
            $uploaded = $this->telegramClient->upload(new TelegramUploadRequest(TelegramType::DOCUMENT, $chatId, $part->stream, basename($path) . '.part' . $index, 'application/octet-stream'));
            $chunks[] = new ChunkMetadata($path, $part->index, TelegramType::DOCUMENT, $part->size, $uploaded->fileId, $uploaded->fileUniqueId, $uploaded->chatId, $uploaded->messageId);
            $index++;
        }

        $file = new FileMetadata($path, TelegramType::DOCUMENT, $size, $mimeType, $visibility, $now, null, null, $chatId, null, true, $chunkSize, count($chunks));
        $this->metadataStore->write($file, $chunks);
    }

    private function metadataOrFail(string $path, string $metadataName): StoredFile
    {
        try {
            $stored = $this->metadataStore->read($path);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, $metadataName, $exception->getMessage(), $exception);
        }

        if ($stored === null) {
            throw UnableToRetrieveMetadata::create($path, $metadataName);
        }

        return $stored;
    }

    private function rewindStream(mixed $stream): void
    {
        if (!is_resource($stream) || !rewind($stream)) {
            throw new \RuntimeException('Unable to rewind stream for upload retry.');
        }
    }

    private function streamSize(mixed $stream): int
    {
        if (!is_resource($stream)) {
            throw new \RuntimeException('Expected stream resource.');
        }

        $stats = fstat($stream);

        if (is_array($stats) && isset($stats['size'])) {
            return (int) $stats['size'];
        }

        $position = ftell($stream);
        fseek($stream, 0, SEEK_END);
        $size = ftell($stream);
        fseek($stream, $position === false ? 0 : $position);

        if ($size === false) {
            throw new \RuntimeException('Unable to determine stream size.');
        }

        return $size;
    }
}
```

- [ ] **Step 6: Verify Flysystem exception factories used by the adapter**

Run:

```bash
php -r "require 'vendor/autoload.php'; foreach ([League\\Flysystem\\UnableToWriteFile::class => ['atLocation'], League\\Flysystem\\UnableToReadFile::class => ['fromLocation'], League\\Flysystem\\UnableToDeleteFile::class => ['atLocation'], League\\Flysystem\\UnableToSetVisibility::class => ['atLocation'], League\\Flysystem\\UnableToMoveFile::class => ['fromLocationTo'], League\\Flysystem\\UnableToCopyFile::class => ['fromLocationTo'], League\\Flysystem\\UnableToRetrieveMetadata::class => ['create']] as $class => $methods) { foreach ($methods as $method) { if (!method_exists($class, $method)) { fwrite(STDERR, $class . '::' . $method . ' missing' . PHP_EOL); exit(1); } } } echo 'Flysystem exception factories verified' . PHP_EOL;"
```

Expected: `Flysystem exception factories verified`.

- [ ] **Step 7: Run adapter tests and verify pass**

Run:

```bash
vendor/bin/phpunit tests/Unit/TelegramAdapterTest.php
```

Expected: PASS.

- [ ] **Step 8: Run all tests**

Run:

```bash
composer test
```

Expected: PASS.

- [ ] **Step 9: Commit adapter**

Run:

```bash
git add src/TelegramAdapter.php tests/Fake/FakeMetadataStore.php tests/Unit/TelegramAdapterTest.php
git commit -m "feat: add Flysystem Telegram adapter"
```

---

### Task 10: Full verification, static analysis, and formatting

**Files:**
- Modify only files required by failures from verification commands.

- [ ] **Step 1: Run full test suite**

Run:

```bash
composer test
```

Expected: PASS.

- [ ] **Step 2: Run static analysis**

Run:

```bash
composer analyse
```

Expected: PASS.

If PHPStan reports missing iterable value types for arrays, add PHPDoc to the exact property or method PHPStan names.

- [ ] **Step 3: Run CS check**

Run:

```bash
composer cs
```

Expected: PASS.

If it fails, run:

```bash
composer cs:fix
```

Then rerun:

```bash
composer cs
```

Expected: PASS.

- [ ] **Step 4: Validate composer package**

Run:

```bash
composer validate
```

Expected: PASS.

- [ ] **Step 5: Commit verification fixes**

If any files changed during verification, run:

```bash
git add src tests composer.json composer.lock phpunit.xml.dist phpstan.neon.dist .php-cs-fixer.dist.php
git commit -m "chore: finalize package checks"
```

If no files changed, do not create an empty commit.

---

## Self-Review Checklist

Spec coverage:

- Package scaffold covers PHP 8.1, Flysystem 3, Guzzle, PDO SQLite, PHPUnit, PHPStan, and PHP-CS-Fixer.
- Config task covers bot token env fallback, prefixed chat id env, and no databasePath env.
- Metadata tasks cover public `MetadataStore`, value objects, SQLite schema, chunks path FK, transactions, WAL, and metadata-only delete.
- Upload strategy task covers `type`, typed upload, size limits, MIME priority, fallback to document/chunked document, and no per-write type override.
- Telegram client task covers typed Bot API methods, multipart fields, `getFile`, and download streams.
- Lazy stream task covers custom protocol, auto registration, lazy chunk download, token release, and non-seekable behavior.
- Adapter task covers Flysystem operations, exception conversion, visibility metadata, read/write streams, move/copy/delete, and default dependency construction.
- Verification task covers tests, static analysis, formatting, and composer validation.

Type consistency:

- The storage type property is consistently named `type`.
- The config size limit property is consistently named `typeSizeLimits`.
- `databasePath` is resolved only from explicit config or cwd default.
- `MetadataStore` path-missing semantics match the design.
- `delete()` does not call Telegram delete APIs.

Execution note:

- This plan includes frequent commits. If the repository has no initial commit, the first commit in Task 1 creates the project baseline.
- Live Telegram tests are intentionally not implemented in this first plan because the default suite must not require real credentials.
