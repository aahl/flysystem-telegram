# Flysystem Telegram Adapter Design

## Overview

Build `aahl/flysystem-telegram`, a PHP 8.1+ Flysystem 3.x adapter that stores file bytes in a Telegram group or channel through the Telegram Bot API. Telegram stores the binary objects; a local metadata store maps Flysystem paths to Telegram `file_id` values and file attributes.

The first implementation provides:

- Guzzle-based Telegram Bot API client.
- Public `MetadataStore` interface.
- Default SQLite metadata store using PDO SQLite.
- Optional chunking for files larger than Telegram method limits.
- Typed Telegram uploads for photos, videos, audio, animations, and documents.
- Lazy chunked `readStream()` through a custom PHP stream wrapper.

## Package

- Composer package: `aahl/flysystem-telegram`
- Namespace: `Aahl\FlysystemTelegram`
- PHP: `^8.1`
- Flysystem: `league/flysystem:^3.0`
- HTTP: `guzzlehttp/guzzle:^7.0`
- Required extensions: `ext-pdo`, `ext-pdo_sqlite`

## High-Level Architecture

```text
Flysystem 3.x
└── TelegramAdapter
    ├── TelegramClientInterface
    │   └── GuzzleTelegramClient
    ├── MetadataStore
    │   └── SqliteMetadataStore
    ├── ChunkManager
    ├── UploadStrategyResolver
    └── Lazy chunk stream
        ├── ChunkedReadStream
        ├── ChunkedReadStreamWrapper
        └── ChunkedReadStreamRegistry
```

`TelegramAdapter` implements Flysystem's `FilesystemAdapter`. It orchestrates path handling, upload strategy resolution, chunking, Telegram API calls, metadata updates, and Flysystem exception conversion.

The adapter must not depend directly on SQLite or Guzzle-specific behavior. It depends on `MetadataStore` and `TelegramClientInterface`, while still offering a simple constructor path that creates `GuzzleTelegramClient` and `SqliteMetadataStore` from config.

## Configuration

`TelegramAdapterConfig` centralizes easy-start configuration:

```php
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
    ) {}
}
```

Config resolution rules:

```text
botToken:
  explicit config value
  → FLYSYSTEM_TELEGRAM_BOT_TOKEN
  → TELEGRAM_BOT_TOKEN
  → InvalidConfiguration

chatId:
  explicit config value
  → FLYSYSTEM_TELEGRAM_CHAT_ID
  → InvalidConfiguration

databasePath:
  explicit config value
  → getcwd() . '/.flysystem-telegram.sqlite'
```

`chatId` intentionally requires the package-prefixed environment variable to avoid accidentally using a Telegram chat configured for another purpose. `botToken` supports both prefixed and common names.

Production documentation should recommend setting `databasePath` explicitly because `getcwd()` can differ between web, queue, cron, and CLI processes.

## Construction API

Simple usage:

```php
$adapter = new TelegramAdapter(
    config: new TelegramAdapterConfig(),
);
```

Advanced usage:

```php
$adapter = new TelegramAdapter(
    config: new TelegramAdapterConfig(),
    telegramClient: $customTelegramClient,
    metadataStore: $customMetadataStore,
    chunkManager: $customChunkManager,
);
```

If `telegramClient` is omitted, the adapter creates `GuzzleTelegramClient` using `botToken`, `apiBaseUri`, and `timeout`. File download URLs are derived from `apiBaseUri`.

If `metadataStore` is omitted, the adapter creates `SqliteMetadataStore` using `databasePath`.

If `chunkManager` is omitted, the adapter creates a default `ChunkManager` using `maxFileSize` and `chunkSize`.

## Metadata Store Interface

`MetadataStore` is a public extension point. Users may provide Redis, MySQL, PostgreSQL, or other implementations.

```php
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

Semantics:

- `read()` returns `null` for missing paths.
- `fileExists()` returns `false` for missing paths.
- `delete()` is idempotent for missing paths.
- Backend failures throw `MetadataStoreException`.
- Flysystem exceptions are emitted only by `TelegramAdapter`.
- `write()` atomically replaces metadata for an existing path.
- `move()` and `copy()` operate only on metadata and do not re-upload Telegram files.

## Metadata Value Objects

```php
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
    ) {}
}
```

```php
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
    ) {}
}
```

```php
final class StoredFile
{
    /**
     * @param list<ChunkMetadata> $chunks
     */
    public function __construct(
        public readonly FileMetadata $metadata,
        public readonly array $chunks = [],
    ) {}

    public function isChunked(): bool
    {
        return $this->metadata->isChunked;
    }
}
```

```php
final class DirectoryMetadata
{
    public function __construct(
        public readonly string $path,
    ) {}
}
```

`type` is the Telegram storage type, not a MIME type. Allowed values are:

```text
photo
video
audio
animation
document
```

Chunked files always use `type = document`.

## SQLite Metadata Store

The default `SqliteMetadataStore` uses PDO SQLite.

```php
final class SqliteMetadataStore implements MetadataStore
{
    public function __construct(
        string $databasePath,
        ?PDO $pdo = null,
    ) {}
}
```

Initialization PRAGMAs:

```sql
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
```

### Schema

```sql
CREATE TABLE files (
    id INTEGER PRIMARY KEY,
    path TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL,
    size INTEGER NOT NULL,
    mime_type TEXT NULL,
    visibility TEXT NULL,
    last_modified INTEGER NOT NULL,
    telegram_file_id TEXT NULL,
    telegram_file_unique_id TEXT NULL,
    telegram_chat_id TEXT NOT NULL,
    telegram_message_id INTEGER NULL,
    is_chunked INTEGER NOT NULL DEFAULT 0,
    chunk_size INTEGER NULL,
    chunk_count INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
```

```sql
CREATE TABLE chunks (
    id INTEGER PRIMARY KEY,
    path TEXT NOT NULL,
    chunk_index INTEGER NOT NULL,
    type TEXT NOT NULL,
    size INTEGER NOT NULL,
    telegram_file_id TEXT NOT NULL,
    telegram_file_unique_id TEXT NULL,
    telegram_chat_id TEXT NOT NULL,
    telegram_message_id INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE (path, chunk_index),
    FOREIGN KEY (path) REFERENCES files(path)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);
```

```sql
CREATE TABLE schema_migrations (
    version INTEGER PRIMARY KEY,
    applied_at INTEGER NOT NULL
);
```

Indexes:

```sql
CREATE INDEX files_path_prefix_idx ON files(path);
CREATE INDEX chunks_path_idx ON chunks(path);
```

Schema choices:

- `files.id` is an internal SQLite row identifier.
- `files.path` is the public business key.
- `chunks.path` references `files.path` with `ON DELETE CASCADE` and `ON UPDATE CASCADE`.
- `telegram_chat_id` is `TEXT` to support numeric IDs and `@channel_username`.
- `telegram_message_id` is nullable.
- No checksum, soft delete, version history, directory table, JSON metadata, or complex `CHECK` constraints in the first version.

For non-chunked files:

```text
files.is_chunked = 0
files.telegram_file_id IS NOT NULL
files.chunk_size IS NULL
files.chunk_count IS NULL
chunks has no rows for the path
```

For chunked files:

```text
files.is_chunked = 1
files.telegram_file_id IS NULL
files.type = document
files.chunk_size IS NOT NULL
files.chunk_count IS NOT NULL
chunks contains chunk_count rows
chunks.type = document
```

These consistency rules are enforced in PHP and tests, not with complex SQLite `CHECK` constraints.

## SQLite Transactions and Concurrency

All write operations use `BEGIN IMMEDIATE` and rollback on failure:

- `write`
- `move`
- `copy`
- `delete`
- `setVisibility`

`write()` replaces existing metadata for the same path:

```sql
BEGIN IMMEDIATE;
DELETE FROM chunks WHERE path = :path;
DELETE FROM files WHERE path = :path;
INSERT INTO files (...);
INSERT INTO chunks (...);
COMMIT;
```

`move()` updates `files.path`; `chunks.path` follows through `ON UPDATE CASCADE`.

`copy()` copies metadata and reuses Telegram `file_id` / `message_id`. The copied file receives a new path and fresh `created_at`, `updated_at`, and `last_modified` values.

`delete()` deletes metadata only. Telegram messages remain in the chat or channel.

Read operations do not open explicit transactions and rely on SQLite WAL snapshot reads.

## Telegram Client

```php
interface TelegramClientInterface
{
    public function upload(TelegramUploadRequest $request): TelegramUploadedFile;

    public function downloadStream(string $fileId): mixed;
}
```

```php
final class TelegramUploadRequest
{
    public function __construct(
        public readonly string $type,
        public readonly string $chatId,
        public readonly mixed $contents,
        public readonly ?string $filename = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $replyToMessageId = null,
    ) {}
}
```

```php
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
    ) {}
}
```

`GuzzleTelegramClient` maps `type` to Bot API method and multipart field:

| type | Bot API method | Multipart field |
|---|---|---|
| `photo` | `sendPhoto` | `photo` |
| `video` | `sendVideo` | `video` |
| `audio` | `sendAudio` | `audio` |
| `animation` | `sendAnimation` | `animation` |
| `document` | `sendDocument` | `document` |

`downloadStream($fileId)` uses `getFile` to retrieve `file_path`, then downloads from the file endpoint and returns a readable PHP resource.

## Upload Strategy

```php
enum UploadTypeStrategy: string
{
    case Auto = 'auto';
    case DocumentOnly = 'document_only';
}
```

`UploadStrategyResolver` determines the final upload type and chunking behavior:

```php
final class UploadStrategyResolver
{
    public function resolve(
        string $path,
        ?string $mimeType,
        int $size,
        TelegramAdapterConfig $config,
    ): TelegramUploadStrategy;
}
```

```php
final class TelegramUploadStrategy
{
    public function __construct(
        public readonly string $type,
        public readonly bool $chunked,
        public readonly int $chunkSize,
    ) {}
}
```

`UploadTypeStrategy::Auto` type detection order:

```text
1. image/gif or .gif → animation
2. image/* → photo
3. video/* → video
4. audio/* → audio
5. fallback → document
```

MIME source priority:

```text
1. Flysystem write config mime_type
2. path extension inference
3. fallback document
```

No `finfo` content detection in the first version.

Single-write `type` override is not supported. Type behavior is controlled globally through `uploadTypeStrategy`.

Default conservative size limits are capped at the hosted Bot API download-safe limit:

```php
[
    'photo' => 10 * 1024 * 1024,
    'video' => 20 * 1024 * 1024,
    'audio' => 20 * 1024 * 1024,
    'animation' => 20 * 1024 * 1024,
    'document' => 20 * 1024 * 1024,
]
```

The hosted Bot API accepts larger non-photo uploads, but `getFile` downloads are limited to 20 MB. Users may override limits globally with `typeSizeLimits` when using a local Bot API server.

Resolution flow:

```text
DocumentOnly:
  type = document

Auto:
  infer candidate type
  if size <= limit[candidate]: use candidate
  else if size <= limit[document]: use document
  else if chunking enabled: use chunked document
  else: fail write
```

`effectiveChunkSize = min(config.chunkSize, limit[document])`.

If a typed upload fails at Telegram, the adapter automatically falls back:

```text
typed upload failure and type != document
→ try document
→ if document fails and chunking is enabled, try chunked document
→ otherwise UnableToWriteFile
```

Metadata stores the final successful `type`.

## File Operation Data Flow

### Write

```text
write/writeStream
→ normalize path and config
→ resolve MIME type
→ resolve upload strategy
→ upload to Telegram using typed method or document/chunked document
→ build FileMetadata and ChunkMetadata records
→ MetadataStore::write()
```

Telegram upload happens before SQLite metadata write. If Telegram upload succeeds but metadata write fails, Telegram orphan messages may remain. The first version does not clean them automatically.

### Read

```text
read/readStream
→ MetadataStore::read(path)
→ if missing, UnableToReadFile
→ if non-chunked, download file_id directly
→ if chunked, validate chunk metadata and return lazy chunk stream
```

`read()` is implemented through `readStream()` and `stream_get_contents()`.

### Delete

```text
delete(path)
→ MetadataStore::delete(path)
```

Deletion only removes metadata. Telegram messages are not deleted.

### Move

```text
move(source, destination)
→ MetadataStore::move(source, destination)
```

No Telegram re-upload. `chunks.path` follows through SQLite cascade.

### Copy

```text
copy(source, destination)
→ MetadataStore::copy(source, destination)
```

No Telegram re-upload. Telegram `file_id` / `message_id` values are reused.

### List Contents

Directories are virtual and prefix-based. No directory rows are stored.

Given:

```text
foo/a.txt
foo/barb.txt
foo/barc.txt
foo/readme.md
```

`listContents('foo', false)` returns:

```text
foo/a.txt
foo/bar
foo/readme.md
```

`listContents('foo', true)` returns:

```text
foo/a.txt
foo/bar
foo/barb.txt
foo/barc.txt
foo/readme.md
```

### Visibility

Visibility is metadata only and does not affect Telegram permissions.

```text
setVisibility(path, public/private)
→ MetadataStore::setVisibility(path, visibility)

visibility(path)
→ return stored visibility or default private
```

## Lazy Chunked Stream

Chunked `readStream()` returns a lazy PHP stream resource. It downloads each Telegram chunk only when the consumer reads far enough to need it.

```php
final class ChunkedReadStream
{
    public static function register(string $protocol): void;

    public static function open(
        string $protocol,
        TelegramClientInterface $telegramClient,
        StoredFile $storedFile,
    ): mixed;
}
```

Internals:

- `ChunkedReadStreamWrapper`
- `ChunkedReadStreamRegistry`

Behavior:

- The wrapper protocol defaults to `flysystem-telegram`.
- Users may customize the protocol through `chunkStreamProtocol`.
- Protocol names are validated with `/^[a-zA-Z][a-zA-Z0-9+.-]*$/`.
- Registration happens automatically once per protocol.
- If a protocol is already registered by this package, it is reused.
- If a protocol is registered by another wrapper, the adapter fails the read.
- `stream_open()` resolves a registry token to its `StoredFile` and client.
- `stream_read()` downloads the current chunk lazily and advances to the next chunk at EOF.
- `stream_close()` releases the registry token.
- If `fopen()` fails, the token is released immediately.
- The first version does not support seek.

Lazy stream errors can occur during `fread()`, not only during `readStream()`. `read()` can catch these errors while consuming the stream and convert them to `UnableToReadFile`. Users directly consuming `readStream()` may see package stream exceptions during reads.

## Error Handling

Internal exceptions:

- `TelegramApiException` for HTTP and Telegram API failures.
- `MetadataStoreException` for metadata backend failures.
- `ChunkedStreamException` for lazy chunk stream failures.
- `InvalidConfiguration` for missing or invalid configuration.

`TelegramAdapter` converts internal exceptions to Flysystem exceptions:

| Operation | Flysystem exception |
|---|---|
| write/writeStream | `UnableToWriteFile` |
| read/readStream | `UnableToReadFile` |
| delete | `UnableToDeleteFile` |
| move | `UnableToMoveFile` |
| copy | `UnableToCopyFile` |
| visibility/mimeType/fileSize/lastModified | `UnableToRetrieveMetadata` |
| setVisibility | `UnableToSetVisibility` |

Missing path behavior:

- `fileExists()` returns `false`.
- `read()` and `readStream()` throw `UnableToReadFile`.
- `delete()` succeeds idempotently.
- `move()` throws `UnableToMoveFile`.
- `copy()` throws `UnableToCopyFile`.
- Metadata reads throw `UnableToRetrieveMetadata`.

Chunked file reads validate:

- chunk count equals `files.chunk_count`.
- chunk indexes are contiguous from `0` to `chunk_count - 1`.
- chunk sizes sum to `files.size`.

Invalid chunk metadata is treated as a read failure.

## Explicit Non-Goals for Version 1

- Real directory creation or deletion.
- Historical versions.
- Checksum/hash verification.
- Automatic Telegram message deletion.
- Garbage collection of orphan Telegram messages.
- Seekable chunked streams.
- Per-write type override.
- `sendVoice`, `sendVideoNote`, or `sendSticker` typed uploads.
- Soft deletes.

## Testing Strategy

Use PHPUnit for tests. Default CI should not require real Telegram credentials.

### Unit Tests

`UploadStrategyResolver`:

- `image/jpeg` and `.jpg` resolve to `photo`.
- `image/gif` and `.gif` resolve to `animation`.
- `video/mp4` resolves to `video`.
- `audio/mpeg` resolves to `audio`.
- unknown types resolve to `document`.
- typed limit overflow falls back to `document`.
- document overflow with chunking enabled resolves to chunked document.
- document overflow with chunking disabled fails.
- `DocumentOnly` always resolves to `document`.

`ChunkManager`:

- small files are not chunked.
- large streams split by configured chunk size.
- chunk metadata validation catches missing, unordered, or size-mismatched chunks.

`TelegramAdapter` with fake dependencies:

- writes use resolved upload strategy.
- reads use metadata and fake Telegram downloads.
- `readStream()` returns a resource.
- deletes remove metadata only.
- move/copy do not upload.
- visibility updates metadata only.
- internal exceptions convert to Flysystem exceptions.

### SQLite Integration Tests

Use real PDO SQLite with a temporary file or `:memory:`:

- schema initialization and migrations.
- non-chunked `write()`.
- chunked `write()`.
- same-path `write()` replaces old chunks.
- `read()` returns complete `StoredFile`.
- `delete()` cascades chunks.
- `move()` cascades `chunks.path` through `ON UPDATE CASCADE`.
- `copy()` duplicates metadata and reuses Telegram IDs.
- shallow and deep `listContents()` produce expected virtual directories.
- `setVisibility()` updates visibility.
- destination conflict on move/copy fails.
- PDO failures are wrapped as `MetadataStoreException`.

### Lazy Chunk Stream Tests

Use a fake Telegram client returning controlled streams:

- no chunk is downloaded before the first read.
- the first read downloads only the first chunk.
- the next chunk downloads only after the previous chunk is exhausted.
- concatenated read output matches original content.
- `fclose()` releases registry tokens.
- failed chunk download raises `ChunkedStreamException`.
- custom protocols work.
- external protocol conflicts fail.
- stream is non-seekable.

### Guzzle Telegram Client Tests

Use Guzzle MockHandler:

- `photo` maps to `sendPhoto` and `photo` multipart field.
- `video` maps to `sendVideo` and `video` multipart field.
- `audio` maps to `sendAudio` and `audio` multipart field.
- `animation` maps to `sendAnimation` and `animation` multipart field.
- `document` maps to `sendDocument` and `document` multipart field.
- Telegram responses parse `file_id`, `file_unique_id`, `message_id`, size, and MIME type.
- `getFile` plus file download returns a readable stream.
- Telegram `ok=false` becomes `TelegramApiException`.
- HTTP 4xx/5xx becomes `TelegramApiException`.

### Optional Live Tests

Live tests are opt-in and excluded from default CI:

```bash
TELEGRAM_BOT_TOKEN=...
FLYSYSTEM_TELEGRAM_CHAT_ID=...
vendor/bin/phpunit --group telegram-live
```

They cover small photo upload, document upload, typed fallback, chunked upload, readback, list, move, copy, and metadata delete.

### CI Commands

Recommended default checks:

```bash
composer validate
composer test
composer analyse
composer cs
```

Suggested tools:

- PHPUnit
- PHPStan
- PHP-CS-Fixer
