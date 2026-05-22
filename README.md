# Flysystem Telegram

A Flysystem 3 storage adapter backed by the Telegram Bot API.

This package treats files attached to Telegram messages as object storage and keeps local metadata for Flysystem paths, Telegram `file_id` values, MIME types, visibility, and chunk information. SQLite is the default metadata store, and the public `MetadataStore` interface allows replacing it with Redis, MySQL, or another backend later.

## Installation

```bash
composer require aahl/flysystem-telegram
```

## Requirements

- PHP 8.1+
- `ext-pdo`
- `ext-pdo_sqlite`
- A Telegram Bot token
- A Telegram chat id where the bot can send messages

## Quick start

```php
<?php

use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\TelegramAdapter;
use League\Flysystem\Filesystem;

$adapter = new TelegramAdapter(new TelegramAdapterConfig(
    botToken: '123456:telegram-bot-token',
    chatId: '-1001234567890',
    databasePath: __DIR__ . '/storage/flysystem-telegram.sqlite',
));

$filesystem = new Filesystem($adapter);

$filesystem->write('docs/hello.txt', 'Hello Telegram');

$contents = $filesystem->read('docs/hello.txt');
```

## Environment variables

`botToken` and `chatId` can be passed explicitly or read from environment variables.

```bash
export FLYSYSTEM_TELEGRAM_BOT_TOKEN="123456:telegram-bot-token"
export FLYSYSTEM_TELEGRAM_CHAT_ID="-1001234567890"
```

The bot token also supports the generic environment variable:

```bash
export TELEGRAM_BOT_TOKEN="123456:telegram-bot-token"
```

`databasePath` is not read from environment variables. If omitted, it defaults to `.flysystem-telegram.sqlite` in the current working directory.

```php
$adapter = new TelegramAdapter(new TelegramAdapterConfig());
```

## Configuration

```php
use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Config\UploadTypeStrategy;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use League\Flysystem\Visibility;

$config = new TelegramAdapterConfig(
    botToken: null,
    chatId: null,
    databasePath: __DIR__ . '/flysystem-telegram.sqlite',
    defaultVisibility: Visibility::PRIVATE,
    enableChunking: true,
    maxFileSize: 50 * 1024 * 1024,
    chunkSize: 20 * 1024 * 1024,
    chunkStreamProtocol: 'flysystem-telegram',
    uploadTypeStrategy: UploadTypeStrategy::Auto,
    typeSizeLimits: [
        TelegramType::PHOTO => 10 * 1024 * 1024,
        TelegramType::VIDEO => 20 * 1024 * 1024,
        TelegramType::AUDIO => 20 * 1024 * 1024,
        TelegramType::ANIMATION => 20 * 1024 * 1024,
        TelegramType::DOCUMENT => 20 * 1024 * 1024,
    ],
    apiBaseUri: 'https://api.telegram.org',
    timeout: 30.0,
);
```

## Upload strategy

The default strategy chooses a Telegram file type from the MIME type or file extension:

- `photo`: images
- `video`: videos
- `audio`: audio files
- `animation`: GIF files
- `document`: everything else

Resolution order:

1. `mime_type` from the Flysystem write config
2. File extension inference
3. `document`

```php
$filesystem->write('video.bin', $contents, [
    'mime_type' => 'video/mp4',
]);
```

By default, non-photo typed uploads and documents use a 20 MB limit even though the hosted Bot API accepts uploads up to 50 MB. This keeps stored files readable through the hosted Bot API, whose `getFile` download path is limited to 20 MB. If a typed upload exceeds its configured size limit, the adapter falls back to `document`. If the file also exceeds the document limit and chunking is enabled, it is uploaded as multiple Telegram documents.

You can raise `typeSizeLimits` and `chunkSize` when using a local Bot API server, which supports larger uploads and unrestricted downloads.

You can also force all uploads to use `document`:

```php
$config = new TelegramAdapterConfig(
    uploadTypeStrategy: UploadTypeStrategy::DocumentOnly,
);
```

## Chunked files

When a file exceeds the document limit and `enableChunking` is `true`, the adapter splits it into multiple Telegram documents and stores the chunk order in metadata.

Chunked reads use a lazy stream: each chunk is downloaded from Telegram only when the stream reaches it. The default chunk size is 20 MB so each chunk remains downloadable through the hosted Bot API.

```php
$stream = $filesystem->readStream('large/archive.zip');

while (!feof($stream)) {
    $chunk = fread($stream, 8192);
}

fclose($stream);
```

If your process already has a stream wrapper with the same name, configure a different `chunkStreamProtocol`.

## MetadataStore

SQLite is used by default:

```php
use Aahl\FlysystemTelegram\Metadata\SqliteMetadataStore;

$metadataStore = new SqliteMetadataStore(__DIR__ . '/flysystem-telegram.sqlite');
```

You may pass any custom `MetadataStore` implementation to `TelegramAdapter`:

```php
use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\TelegramAdapter;

/** @var MetadataStore $metadataStore */
$adapter = new TelegramAdapter(
    new TelegramAdapterConfig(botToken: 'token', chatId: '-1001234567890'),
    metadataStore: $metadataStore,
);
```

Custom stores must implement `Aahl\FlysystemTelegram\Metadata\MetadataStore`.

## Behavior notes

- `delete()` and `deleteDirectory()` remove metadata only. They do not delete Telegram messages or files.
- Flysystem visibility is stored only as metadata. It does not change Telegram-side access control.
- `copy()` duplicates metadata and reuses Telegram `file_id` values. It does not upload the file again.
- `move()` updates the metadata path. It does not move the Telegram-side file.
- `createDirectory()` is a no-op. Directories are virtual and derived from file path prefixes.

## Development

```bash
composer install
composer test
composer analyse
composer cs
```

Fix code style automatically:

```bash
composer cs:fix
```

## License

MIT
