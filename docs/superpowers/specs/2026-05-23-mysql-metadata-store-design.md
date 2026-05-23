# MySQL Metadata Store Design

## Overview

Add an optional MySQL/MariaDB metadata store for `aahl/flysystem-telegram`. Telegram remains the binary storage backend; MySQL/MariaDB stores the Flysystem path metadata, Telegram `file_id` references, attributes, and chunk records.

SQLite remains the default metadata store. MySQL/MariaDB is an opt-in store for projects that already operate a relational database and prefer not to manage a local SQLite file.

## Goals

- Provide `MysqlMetadataStore` as a first-party `MetadataStore` implementation.
- Support both existing `PDO` connections and DSN-based construction.
- Support table prefixes so multiple instances can share one database.
- Auto-create tables by default, with an option to disable initialization for framework-managed migrations.
- Keep SQLite behavior and the public `MetadataStore` interface unchanged.
- Keep `pdo_mysql` optional so SQLite users do not need MySQL extensions installed.
- Add shared metadata store contract tests that can run against SQLite, MySQL, and future stores.

## Non-Goals

- Do not make MySQL/MariaDB the default metadata store.
- Do not add a SQL base class before a third SQL implementation exists.
- Do not implement a full migration runner in the first version.
- Do not expose arbitrary table names; only a safe table prefix is configurable.
- Do not document internal DDL details in the README.

## Public API

Add:

```php
namespace Aahl\FlysystemTelegram\Metadata;

use PDO;

final class MysqlMetadataStore implements MetadataStore
{
    /**
     * @param array<int, mixed> $options
     */
    public function __construct(
        ?PDO $pdo = null,
        ?string $dsn = null,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        string $tablePrefix = '',
        bool $autoInitialize = true,
    ) {
    }
}
```

Construction rules:

- If `pdo` is provided, use it and ignore DSN parameters.
- If `pdo` is not provided, `dsn` is resolved in this order:
  - explicit `dsn` parameter
  - `FLYSYSTEM_TELEGRAM_DB_DSN`
- If `dsn` is resolved this way, `username` falls back to the explicit parameter, then `FLYSYSTEM_TELEGRAM_DB_USER`, then `null`.
- If `dsn` is resolved this way, `password` falls back to the explicit parameter, then `FLYSYSTEM_TELEGRAM_DB_PASS`, then `null`.
- If neither `pdo` nor resolved `dsn` is provided, throw `MetadataStoreException`.
- Set `PDO::ATTR_ERRMODE` to `PDO::ERRMODE_EXCEPTION` on the active connection.
- If `autoInitialize` is true, create the required tables and record schema version 1. Repeated construction with the same tables is idempotent.
- `tablePrefix` defaults to an empty string.
- `tablePrefix` must match `/^[A-Za-z0-9_]{0,32}$/`; otherwise throw `MetadataStoreException`.

The adapter integration remains unchanged:

```php
$adapter = new TelegramAdapter($config, metadataStore: $store);
```

## Composer Dependency

Keep both PDO-based metadata store drivers as suggestions, not hard requirements:

```json
{
  "suggest": {
    "ext-pdo_sqlite": "Required to use SqliteMetadataStore.",
    "ext-pdo_mysql": "Required to use MysqlMetadataStore."
  }
}
```

A default installation without a suggested database driver can still work if the user supplies a custom `MetadataStore`. If no custom `MetadataStore` is provided and the required driver extension is missing, the default SQLite construction path will fail at runtime. This keeps SQLite-only setups from requiring MySQL support, and MySQL-only setups from requiring SQLite support.

## Database Compatibility

`MysqlMetadataStore` targets modern MySQL/MariaDB with InnoDB and `utf8mb4`. It does not support MyISAM or database engines without foreign key support.

All generated tables use:

```sql
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## Schema

`autoInitialize=true` creates three tables:

- `{prefix}files`
- `{prefix}chunks`
- `{prefix}schema_migrations`

The prefix is validated before being interpolated into SQL identifiers. Table names are always composed from the prefix plus fixed suffixes.

### Files Table

```sql
CREATE TABLE IF NOT EXISTS `{prefix}files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `path` VARCHAR(1023) NOT NULL,
  `path_hash` BINARY(32) NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `size` BIGINT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(255) NULL,
  `visibility` VARCHAR(32) NULL,
  `last_modified` INT UNSIGNED NOT NULL,
  `telegram_file_id` VARCHAR(255) NULL,
  `telegram_file_unique_id` VARCHAR(255) NULL,
  `telegram_chat_id` VARCHAR(255) NOT NULL,
  `telegram_message_id` BIGINT NULL,
  `is_chunked` TINYINT(1) NOT NULL DEFAULT 0,
  `chunk_size` BIGINT UNSIGNED NULL,
  `chunk_count` INT UNSIGNED NULL,
  `created_at` INT UNSIGNED NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `{prefix}files_path_hash_unique` (`path_hash`),
  KEY `{prefix}files_path_prefix_idx` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Chunks Table

```sql
CREATE TABLE IF NOT EXISTS `{prefix}chunks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `chunk_index` INT UNSIGNED NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `size` BIGINT UNSIGNED NOT NULL,
  `telegram_file_id` VARCHAR(255) NOT NULL,
  `telegram_file_unique_id` VARCHAR(255) NULL,
  `telegram_chat_id` VARCHAR(255) NOT NULL,
  `telegram_message_id` BIGINT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `{prefix}chunks_file_index_unique` (`file_id`, `chunk_index`),
  CONSTRAINT `{prefix}chunks_file_foreign`
    FOREIGN KEY (`file_id`) REFERENCES `{prefix}files` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Schema Migrations Table

```sql
CREATE TABLE IF NOT EXISTS `{prefix}schema_migrations` (
  `version` INT UNSIGNED NOT NULL,
  `applied_at` INT UNSIGNED NOT NULL,

  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

After initialization, record version 1:

```sql
INSERT IGNORE INTO `{prefix}schema_migrations` (`version`, `applied_at`)
VALUES (1, :applied_at);
```

This records the initial schema without introducing a general-purpose migration runner. Repeated initialization is safe because `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE` are used for all bootstrap statements.

## Path Storage and Indexing

`files.path` stores the full Flysystem path and supports up to 1023 bytes. MySQL prefix indexes are not used for uniqueness because a unique prefix index would reject distinct paths that share the first 191 characters.

`files.path_hash` stores `hash('sha256', $path, true)` and provides the uniqueness constraint:

```sql
UNIQUE KEY `{prefix}files_path_hash_unique` (`path_hash`)
```

All exact path operations include both the hash and the path:

```sql
WHERE `path_hash` = :path_hash AND `path` = :path
```

This prevents returning the wrong record if a theoretical SHA-256 collision occurs. A collision would still cause the unique index to reject the second insert, which is acceptable for this store.

The prefix index is only for listing:

```sql
KEY `{prefix}files_path_prefix_idx` (`path`(191))
```

`chunks` reference files by `file_id`, not path. This avoids long string foreign keys and lets `move()` update only the file row.

## Operation Semantics

All entry points that accept a path validate length early. `write` validates the file path, and all other path-based operations validate their input paths before querying. This keeps long-path failures consistent and avoids relying on MySQL-specific errors.

### write

`write(FileMetadata $file, array $chunks = [])`:

1. Validate that `$file->path` is no more than 1023 bytes.
2. Start a transaction.
3. Delete any existing file using `path_hash + path`; chunk rows are removed through `ON DELETE CASCADE`.
4. Insert the new file row with `path_hash`.
5. Use `lastInsertId()` as the `file_id` for chunk rows.
6. Insert all chunks ordered by the caller-provided array.
7. Commit the transaction.

### read

`read(string $path)`:

1. Validate path length.
2. Select the file row by `path_hash + path`.
3. Return `null` when the file does not exist.
4. Select chunk rows by `file_id ORDER BY chunk_index ASC`.
5. Hydrate `StoredFile` with `FileMetadata` and `ChunkMetadata` values.
6. When hydrating chunks, use the parent file path as `ChunkMetadata::$path` to preserve the public metadata model.

### delete

`delete(string $path)` deletes the file row by `path_hash + path`. Missing paths are ignored, matching the current SQLite behavior.

### fileExists

`fileExists(string $path)` checks for a row by `path_hash + path` and returns a boolean.

### listContents

`listContents(string $path, bool $deep)` queries only the files table:

```sql
SELECT * FROM `{prefix}files`
WHERE `path` LIKE :prefix
ORDER BY `path` ASC
```

PHP derives virtual directory entries from stored file paths using the same shallow/deep behavior as `SqliteMetadataStore`.

### move

`move(string $source, string $destination)`:

1. Validate both paths.
2. Update the file row selected by source `path_hash + path`.
3. Set `path`, `path_hash`, and `updated_at` to destination values.
4. Leave chunks unchanged because they reference the file by `file_id`.
5. Throw `MetadataStoreException` when no source row was updated.

### copy

`copy(string $source, string $destination)`:

1. Read the source file and chunks.
2. Throw `MetadataStoreException` if the source path does not exist.
3. Insert a destination file row with a new `path_hash`.
4. Copy chunk rows to the new `file_id`.
5. Reuse Telegram `file_id` values, matching SQLite copy behavior.

### setVisibility

`setVisibility(string $path, string $visibility)` updates visibility by `path_hash + path` and throws `MetadataStoreException` when the path does not exist.

## Transactions and Errors

Mutating operations use transactions:

- `write`
- `delete`
- `move`
- `copy`
- `setVisibility`

`PDOException` is wrapped in `MetadataStoreException`. Store-level validation errors also use `MetadataStoreException`, including:

- Missing DSN/PDO configuration.
- Invalid table prefix.
- Provided path longer than 1023 bytes.
- Missing source path for `move()` or `copy()`.
- Missing path for `setVisibility()`.

`TelegramAdapter` already converts metadata store failures into Flysystem exceptions at the adapter boundary.

## Contract Tests

Add shared metadata store behavior tests in:

```text
tests/Contract/MetadataStoreContractTestTrait.php
```

The trait defines tests for the `MetadataStore` contract only. It must not assert SQLite- or MySQL-specific implementation details. Each concrete store test exposes the current store through a protected `metadataStore()` helper, and owns fresh instance setup and cleanup through its normal PHPUnit `setUp()` and `tearDown()` methods.

Covered behavior:

- Write and read a non-chunked file.
- Write and read a chunked file.
- Read chunks ordered by `chunk_index`.
- Delete a file and cascade chunk deletion.
- Check file existence.
- List shallow contents with virtual directories.
- List deep contents with intermediate virtual directories.
- Move a file and keep chunks readable.
- Copy a file and copy chunk metadata.
- Set visibility.

### SQLite Contract Test

Update:

```text
tests/Integration/Metadata/SqliteMetadataStoreTest.php
```

It adopts the contract trait incrementally and continues to create a temporary SQLite database per test. Keep SQLite-specific assertions in `SqliteMetadataStoreTest`; move only shared `MetadataStore` behavior into the contract trait. The migration should not require rewriting all existing SQLite tests at once.

### MySQL Contract and Integration Tests

Add:

```text
tests/Integration/Metadata/MysqlMetadataStoreTest.php
```

The MySQL test class uses the same contract trait and adds MySQL-specific tests.

MySQL tests run only when `FLYSYSTEM_TELEGRAM_DB_DSN` is set. Otherwise they are skipped.

Supported test environment variables:

```text
FLYSYSTEM_TELEGRAM_DB_DSN
FLYSYSTEM_TELEGRAM_DB_USER
FLYSYSTEM_TELEGRAM_DB_PASS
```

Each test method uses a random table prefix such as:

```text
fly_a1b2c3_
```

After each test, drop the generated tables for that prefix:

- `{prefix}chunks`
- `{prefix}files`
- `{prefix}schema_migrations`

MySQL-specific tests cover:

- Table prefix creation.
- `autoInitialize=false` not creating tables.
- Path length validation above 1023 bytes on read, write, and other path-based operations.
- Two paths sharing the first 191 characters but differing later can coexist.
- Invalid table prefixes throw `MetadataStoreException`.
- Both PDO-based and DSN-based construction work.
- Schema initialization records version 1.
- Repeated initialization with the same prefix is idempotent and does not duplicate schema version records.

## README Updates

Keep SQLite as the default metadata store in the main usage example.

The README should explain suggested metadata driver extensions near installation or metadata store documentation:

- `ext-pdo_sqlite` is required for the default SQLite metadata store.
- `ext-pdo_mysql` is required for `MysqlMetadataStore`.
- A project that cannot install the default SQLite driver must pass a custom `MetadataStore` or use `MysqlMetadataStore`.

The default SQLite documentation should state that `databasePath` is only used by the default SQLite metadata store.

Add a `MySQL / MariaDB` subsection under metadata store documentation. The README should include:

- MySQL/MariaDB is optional and can replace the default local SQLite metadata file.
- It requires `ext-pdo_mysql`.
- It supports DSN construction.
- It supports existing PDO connections.
- It supports `tablePrefix`.
- It auto-creates tables by default.
- `autoInitialize` can be disabled.
- Paths support up to 1023 bytes.
- `MysqlMetadataStore` can resolve DSN, user, and password from `FLYSYSTEM_TELEGRAM_DB_DSN`, `FLYSYSTEM_TELEGRAM_DB_USER`, and `FLYSYSTEM_TELEGRAM_DB_PASS` when explicit parameters are not provided.
- MySQL integration tests use the same `FLYSYSTEM_TELEGRAM_DB_*` variables.

Use explicit `new TelegramAdapterConfig(...)` examples.

### DSN Example

```php
use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Metadata\MysqlMetadataStore;
use Aahl\FlysystemTelegram\TelegramAdapter;

$config = new TelegramAdapterConfig(
    botToken: 'BOT_TOKEN',
    chatId: '-100123456789',
);

$store = new MysqlMetadataStore(
    dsn: 'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    username: 'app',
    password: 'secret',
    tablePrefix: 'flysystem_',
);

$adapter = new TelegramAdapter($config, metadataStore: $store);
```

### PDO Example

```php
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    'app',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$store = new MysqlMetadataStore(
    pdo: $pdo,
    tablePrefix: 'flysystem_',
);

$adapter = new TelegramAdapter($config, metadataStore: $store);
```

### Disable Auto-Initialization

```php
$store = new MysqlMetadataStore(
    pdo: $pdo,
    tablePrefix: 'flysystem_',
    autoInitialize: false,
);
```

### MySQL Integration Tests

```bash
export FLYSYSTEM_TELEGRAM_DB_DSN="mysql:host=127.0.0.1;dbname=test;charset=utf8mb4"
export FLYSYSTEM_TELEGRAM_DB_USER="root"
export FLYSYSTEM_TELEGRAM_DB_PASS=""

composer test
```

## Implementation Notes

- Keep `MysqlMetadataStore` independent from `SqliteMetadataStore`.
- Duplicate small hydration and list logic for now instead of introducing an abstract SQL store.
- Use helper methods for table names so all SQL identifier interpolation flows through prefix validation.
- Use prepared statements for all values.
- Interpolate only validated table and index identifiers.
- Preserve existing Flysystem adapter behavior by keeping all metadata-store-specific failures behind `MetadataStoreException`.
