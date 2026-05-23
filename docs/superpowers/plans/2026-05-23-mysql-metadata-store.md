# MySQL Metadata Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an optional MySQL/MariaDB `MetadataStore` implementation with shared contract tests and documentation.

**Architecture:** Keep `SqliteMetadataStore` as the default store and add `MysqlMetadataStore` as an opt-in implementation of the existing `MetadataStore` interface. Do not change `TelegramAdapter` or introduce a shared SQL base class; duplicate the small hydration and listing logic until a third SQL store justifies extraction.

**Tech Stack:** PHP 8.1, PDO, PDO SQLite, PDO MySQL, PHPUnit 10, Flysystem 3, Composer.

---

## File Structure

- Create: `src/Metadata/MysqlMetadataStore.php`
  - First-party MySQL/MariaDB implementation of `MetadataStore`.
  - Supports PDO or DSN construction.
  - Supports `FLYSYSTEM_TELEGRAM_DB_DSN`, `FLYSYSTEM_TELEGRAM_DB_USER`, and `FLYSYSTEM_TELEGRAM_DB_PASS` fallback.
  - Creates `{prefix}files`, `{prefix}chunks`, and `{prefix}schema_migrations` when `autoInitialize=true`.

- Create: `tests/Contract/MetadataStoreContractTestTrait.php`
  - Shared behavior tests for every `MetadataStore` implementation.
  - Calls `metadataStore()` on the concrete test class.
  - Relies on each concrete test class to create a fresh store in `setUp()` and clean it up in `tearDown()`.
  - Does not test SQLite- or MySQL-specific internals.

- Modify: `tests/Integration/Metadata/SqliteMetadataStoreTest.php`
  - Add the contract trait incrementally.
  - Add a `metadataStore()` helper returning the existing `$store`.
  - Keep current SQLite-specific tests intact.

- Create: `tests/Integration/Metadata/MysqlMetadataStoreTest.php`
  - MySQL integration tests gated by `FLYSYSTEM_TELEGRAM_DB_DSN`.
  - Uses a random table prefix per test method.
  - Runs the shared contract trait plus MySQL-specific assertions.

- Modify: `composer.json`
  - Keep `ext-pdo` as a hard requirement.
  - Move `ext-pdo_sqlite` from `require` to `suggest`.
  - Add `ext-pdo_mysql` to `suggest`.

- Modify: `README.md`
  - Explain metadata driver extension choices.
  - Clarify SQLite default behavior and `databasePath` scope.
  - Add MySQL/MariaDB metadata store examples.
  - Add MySQL integration test environment variables.

---

### Task 1: Update Composer Metadata Driver Requirements

**Files:**
- Modify: `composer.json:6-12`

- [ ] **Step 1: Change dependency metadata**

Edit `composer.json` so the dependency block becomes:

```json
  "require": {
    "php": "^8.1",
    "ext-pdo": "*",
    "guzzlehttp/guzzle": "^7.0",
    "league/flysystem": "^3.0"
  },
  "require-dev": {
    "friendsofphp/php-cs-fixer": "^3.64",
    "phpstan/phpstan": "^1.12",
    "phpunit/phpunit": "^10.5"
  },
  "suggest": {
    "ext-pdo_mysql": "Required to use MysqlMetadataStore.",
    "ext-pdo_sqlite": "Required to use SqliteMetadataStore."
  },
```

Keep the rest of the file unchanged.

- [ ] **Step 2: Validate Composer metadata**

Run:

```bash
composer validate
```

Expected: Composer reports the package metadata is valid. It may warn that the package has no lock file only if the local environment lacks one; do not change dependency versions for that warning.

- [ ] **Step 3: Commit dependency metadata change**

```bash
git add composer.json
git commit -m "$(cat <<'EOF'
chore: make metadata store drivers optional

Generated with [Claude Code](https://claude.ai/code)
via [Happy](https://happy.engineering)

Co-Authored-By: Claude <noreply@anthropic.com>
Co-Authored-By: Happy <yesreply@happy.engineering>
EOF
)"
```

---

### Task 2: Add Shared MetadataStore Contract Tests

**Files:**
- Create: `tests/Contract/MetadataStoreContractTestTrait.php`

- [ ] **Step 1: Create the contract test trait**

Create `tests/Contract/MetadataStoreContractTestTrait.php` with this content:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Contract;

use Aahl\FlysystemTelegram\Metadata\ChunkMetadata;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Assert;

trait MetadataStoreContractTestTrait
{
    abstract protected function metadataStore(): MetadataStore;

    public function testMetadataStoreWritesAndReadsNonChunkedFile(): void
    {
        $file = new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, 'text/plain', Visibility::PRIVATE, 123, 'file-id', 'unique-id', '-100', 77);

        $this->metadataStore()->write($file);
        $stored = $this->metadataStore()->read('docs/a.txt');

        Assert::assertNotNull($stored);
        Assert::assertSame('docs/a.txt', $stored->metadata->path);
        Assert::assertSame(TelegramType::DOCUMENT, $stored->metadata->type);
        Assert::assertSame(5, $stored->metadata->size);
        Assert::assertSame('text/plain', $stored->metadata->mimeType);
        Assert::assertSame(Visibility::PRIVATE, $stored->metadata->visibility);
        Assert::assertSame(123, $stored->metadata->lastModified);
        Assert::assertSame('file-id', $stored->metadata->telegramFileId);
        Assert::assertSame('unique-id', $stored->metadata->telegramFileUniqueId);
        Assert::assertSame('-100', $stored->metadata->telegramChatId);
        Assert::assertSame(77, $stored->metadata->telegramMessageId);
        Assert::assertFalse($stored->isChunked());
        Assert::assertSame([], $stored->chunks);
    }

    public function testMetadataStoreReturnsNullForMissingPath(): void
    {
        Assert::assertNull($this->metadataStore()->read('missing.txt'));
    }

    public function testMetadataStoreReportsFileExistence(): void
    {
        Assert::assertFalse($this->metadataStore()->fileExists('docs/a.txt'));

        $this->metadataStore()->write(new FileMetadata('docs/a.txt', TelegramType::DOCUMENT, 5, null, null, 123, 'file-id', null, '-100', 77));

        Assert::assertTrue($this->metadataStore()->fileExists('docs/a.txt'));
    }

    public function testMetadataStoreWritesAndReadsChunkedFileInOrder(): void
    {
        $file = new FileMetadata('big.bin', TelegramType::DOCUMENT, 9, null, Visibility::PRIVATE, 123, null, null, '-100', null, true, 4, 3);
        $chunks = [
            new ChunkMetadata('big.bin', 2, TelegramType::DOCUMENT, 1, 'file-2', null, '-100', 12),
            new ChunkMetadata('big.bin', 0, TelegramType::DOCUMENT, 4, 'file-0', null, '-100', 10),
            new ChunkMetadata('big.bin', 1, TelegramType::DOCUMENT, 4, 'file-1', null, '-100', 11),
        ];

        $this->metadataStore()->write($file, $chunks);
        $stored = $this->metadataStore()->read('big.bin');

        Assert::assertNotNull($stored);
        Assert::assertTrue($stored->isChunked());
        Assert::assertNull($stored->metadata->telegramFileId);
        Assert::assertSame(4, $stored->metadata->chunkSize);
        Assert::assertSame(3, $stored->metadata->chunkCount);
        Assert::assertCount(3, $stored->chunks);
        Assert::assertSame([0, 1, 2], array_map(static fn (ChunkMetadata $chunk): int => $chunk->index, $stored->chunks));
        Assert::assertSame(['file-0', 'file-1', 'file-2'], array_map(static fn (ChunkMetadata $chunk): string => $chunk->telegramFileId, $stored->chunks));
    }

    public function testMetadataStoreWriteReplacesExistingFileAndOldChunks(): void
    {
        $this->metadataStore()->write(
            new FileMetadata('same.bin', TelegramType::DOCUMENT, 8, null, null, 100, null, null, '-100', null, true, 4, 2),
            [
                new ChunkMetadata('same.bin', 0, TelegramType::DOCUMENT, 4, 'old-0', null, '-100', 10),
                new ChunkMetadata('same.bin', 1, TelegramType::DOCUMENT, 4, 'old-1', null, '-100', 11),
            ],
        );

        $this->metadataStore()->write(new FileMetadata('same.bin', TelegramType::DOCUMENT, 3, null, null, 200, 'new-file', null, '-100', 20));
        $stored = $this->metadataStore()->read('same.bin');

        Assert::assertNotNull($stored);
        Assert::assertFalse($stored->isChunked());
        Assert::assertSame('new-file', $stored->metadata->telegramFileId);
        Assert::assertSame([], $stored->chunks);
    }

    public function testMetadataStoreDeleteRemovesFileAndChunks(): void
    {
        $this->metadataStore()->write(
            new FileMetadata('delete.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('delete.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->metadataStore()->delete('delete.bin');

        Assert::assertNull($this->metadataStore()->read('delete.bin'));
        Assert::assertFalse($this->metadataStore()->fileExists('delete.bin'));
    }

    public function testMetadataStoreDeleteMissingPathIsNoOp(): void
    {
        $this->metadataStore()->delete('missing.txt');

        Assert::assertFalse($this->metadataStore()->fileExists('missing.txt'));
    }

    public function testMetadataStoreMoveUpdatesPathAndKeepsChunksReadable(): void
    {
        $this->metadataStore()->write(
            new FileMetadata('from/big.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('from/big.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->metadataStore()->move('from/big.bin', 'to/big.bin');

        Assert::assertNull($this->metadataStore()->read('from/big.bin'));
        $stored = $this->metadataStore()->read('to/big.bin');
        Assert::assertNotNull($stored);
        Assert::assertSame('to/big.bin', $stored->metadata->path);
        Assert::assertSame('to/big.bin', $stored->chunks[0]->path);
        Assert::assertSame('chunk', $stored->chunks[0]->telegramFileId);
    }

    public function testMetadataStoreCopyDuplicatesMetadataAndChunks(): void
    {
        $this->metadataStore()->write(
            new FileMetadata('source.bin', TelegramType::DOCUMENT, 4, null, null, 100, null, null, '-100', null, true, 4, 1),
            [new ChunkMetadata('source.bin', 0, TelegramType::DOCUMENT, 4, 'chunk', null, '-100', 10)],
        );

        $this->metadataStore()->copy('source.bin', 'copy.bin');

        $source = $this->metadataStore()->read('source.bin');
        $copy = $this->metadataStore()->read('copy.bin');

        Assert::assertNotNull($source);
        Assert::assertNotNull($copy);
        Assert::assertSame('source.bin', $source->metadata->path);
        Assert::assertSame('copy.bin', $copy->metadata->path);
        Assert::assertSame('chunk', $copy->chunks[0]->telegramFileId);
        Assert::assertSame('copy.bin', $copy->chunks[0]->path);
        Assert::assertGreaterThanOrEqual($source->metadata->lastModified, $copy->metadata->lastModified);
    }

    public function testMetadataStoreSetVisibilityUpdatesExistingFile(): void
    {
        $this->metadataStore()->write(new FileMetadata('visible.txt', TelegramType::DOCUMENT, 5, null, Visibility::PRIVATE, 100, 'file', null, '-100', 10));

        $this->metadataStore()->setVisibility('visible.txt', Visibility::PUBLIC);

        Assert::assertSame(Visibility::PUBLIC, $this->metadataStore()->read('visible.txt')?->metadata->visibility);
    }

    public function testMetadataStoreListContentsShallowReturnsFilesAndVirtualDirectories(): void
    {
        $this->metadataStore()->write(new FileMetadata('foo/a.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'a', null, '-100', 1));
        $this->metadataStore()->write(new FileMetadata('foo/bar/b.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'b', null, '-100', 2));
        $this->metadataStore()->write(new FileMetadata('foo/bar/c.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'c', null, '-100', 3));
        $this->metadataStore()->write(new FileMetadata('foo/readme.md', TelegramType::DOCUMENT, 1, null, null, 100, 'r', null, '-100', 4));

        $items = iterator_to_array($this->metadataStore()->listContents('foo', false));
        $paths = array_map(static fn (object $item): string => $item->path, $items);

        sort($paths);

        Assert::assertSame(['foo/a.txt', 'foo/bar', 'foo/readme.md'], $paths);
    }

    public function testMetadataStoreListContentsDeepReturnsFilesAndVirtualDirectories(): void
    {
        $this->metadataStore()->write(new FileMetadata('foo/a.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'a', null, '-100', 1));
        $this->metadataStore()->write(new FileMetadata('foo/bar/b.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'b', null, '-100', 2));
        $this->metadataStore()->write(new FileMetadata('foo/bar/baz/c.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'c', null, '-100', 3));

        $items = iterator_to_array($this->metadataStore()->listContents('foo', true));
        $paths = array_map(static fn (object $item): string => $item->path, $items);

        sort($paths);

        Assert::assertSame(['foo/a.txt', 'foo/bar', 'foo/bar/b.txt', 'foo/bar/baz', 'foo/bar/baz/c.txt'], $paths);
    }
}
```

- [ ] **Step 2: Check the new trait syntax**

Run:

```bash
php -l tests/Contract/MetadataStoreContractTestTrait.php
```

Expected: `No syntax errors detected in tests/Contract/MetadataStoreContractTestTrait.php`.

- [ ] **Step 3: Commit contract trait**

```bash
git add tests/Contract/MetadataStoreContractTestTrait.php
git commit -m "$(cat <<'EOF'
test: add metadata store contract coverage

Generated with [Claude Code](https://claude.ai/code)
via [Happy](https://happy.engineering)

Co-Authored-By: Claude <noreply@anthropic.com>
Co-Authored-By: Happy <yesreply@happy.engineering>
EOF
)"
```

---

### Task 3: Adopt Contract Tests in SQLite Store

**Files:**
- Modify: `tests/Integration/Metadata/SqliteMetadataStoreTest.php:7-18`

- [ ] **Step 1: Import the contract trait and interface**

Add these imports to `tests/Integration/Metadata/SqliteMetadataStoreTest.php`:

```php
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\Tests\Contract\MetadataStoreContractTestTrait;
```

- [ ] **Step 2: Use the trait and expose the store helper**

Change the start of the class to:

```php
final class SqliteMetadataStoreTest extends TestCase
{
    use MetadataStoreContractTestTrait;

    private string $databasePath;
    private SqliteMetadataStore $store;

    protected function metadataStore(): MetadataStore
    {
        return $this->store;
    }
```

Keep the existing `setUp()`, `tearDown()`, and SQLite tests unchanged.

- [ ] **Step 3: Run SQLite metadata tests**

Run:

```bash
vendor/bin/phpunit tests/Integration/Metadata/SqliteMetadataStoreTest.php
```

Expected: All SQLite metadata tests pass. The test count increases because the shared contract tests now also run against SQLite.

- [ ] **Step 4: Commit SQLite contract adoption**

```bash
git add tests/Integration/Metadata/SqliteMetadataStoreTest.php
git commit -m "$(cat <<'EOF'
test: run metadata contract against SQLite store

Generated with [Claude Code](https://claude.ai/code)
via [Happy](https://happy.engineering)

Co-Authored-By: Claude <noreply@anthropic.com>
Co-Authored-By: Happy <yesreply@happy.engineering>
EOF
)"
```

---

### Task 4: Add MySQL Integration Tests Before Implementation

**Files:**
- Create: `tests/Integration/Metadata/MysqlMetadataStoreTest.php`

- [ ] **Step 1: Create the failing MySQL test file**

Create `tests/Integration/Metadata/MysqlMetadataStoreTest.php` with this content:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Integration\Metadata;

use Aahl\FlysystemTelegram\Exception\MetadataStoreException;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\Metadata\MysqlMetadataStore;
use Aahl\FlysystemTelegram\Tests\Contract\MetadataStoreContractTestTrait;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use PDO;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class MysqlMetadataStoreTest extends TestCase
{
    use MetadataStoreContractTestTrait;

    private PDO $pdo;
    private string $tablePrefix;
    private MysqlMetadataStore $store;

    protected function setUp(): void
    {
        $dsn = getenv('FLYSYSTEM_TELEGRAM_DB_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set FLYSYSTEM_TELEGRAM_DB_DSN to run MySQL metadata store tests.');
        }

        $username = getenv('FLYSYSTEM_TELEGRAM_DB_USER');
        $password = getenv('FLYSYSTEM_TELEGRAM_DB_PASS');

        $this->pdo = new PDO(
            $dsn,
            $username === false ? null : $username,
            $password === false ? null : $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->tablePrefix = 'fly_' . bin2hex(random_bytes(4)) . '_';
        $this->store = new MysqlMetadataStore(pdo: $this->pdo, tablePrefix: $this->tablePrefix);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->tablePrefix)) {
            return;
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS `' . $this->tablePrefix . 'chunks`');
        $this->pdo->exec('DROP TABLE IF EXISTS `' . $this->tablePrefix . 'files`');
        $this->pdo->exec('DROP TABLE IF EXISTS `' . $this->tablePrefix . 'schema_migrations`');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function metadataStore(): MetadataStore
    {
        return $this->store;
    }

    public function testCreatesPrefixedTables(): void
    {
        Assert::assertTrue($this->tableExists($this->tablePrefix . 'files'));
        Assert::assertTrue($this->tableExists($this->tablePrefix . 'chunks'));
        Assert::assertTrue($this->tableExists($this->tablePrefix . 'schema_migrations'));
    }

    public function testAutoInitializeCanBeDisabled(): void
    {
        $prefix = 'fly_' . bin2hex(random_bytes(4)) . '_';
        new MysqlMetadataStore(pdo: $this->pdo, tablePrefix: $prefix, autoInitialize: false);

        try {
            Assert::assertFalse($this->tableExists($prefix . 'files'));
            Assert::assertFalse($this->tableExists($prefix . 'chunks'));
            Assert::assertFalse($this->tableExists($prefix . 'schema_migrations'));
        } finally {
            $this->dropPrefixTables($prefix);
        }
    }

    public function testPathsLongerThanLimitFailBeforeDatabaseQuery(): void
    {
        $path = str_repeat('a', 1024);

        foreach (
            [
                static function (MysqlMetadataStore $store) use ($path): void {
                    $store->write(new FileMetadata($path, TelegramType::DOCUMENT, 1, null, null, 100, 'file', null, '-100', 1));
                },
                static function (MysqlMetadataStore $store) use ($path): void {
                    $store->read($path);
                },
                static function (MysqlMetadataStore $store) use ($path): void {
                    $store->delete($path);
                },
                static function (MysqlMetadataStore $store) use ($path): void {
                    $store->fileExists($path);
                },
                static function (MysqlMetadataStore $store) use ($path): void {
                    iterator_to_array($store->listContents($path, false));
                },
                static function (MysqlMetadataStore $store) use ($path): void {
                    $store->move($path, 'destination.txt');
                },
                static function (MysqlMetadataStore $store) use ($path): void {
                    $store->move('source.txt', $path);
                },
                static function (MysqlMetadataStore $store) use ($path): void {
                    $store->copy($path, 'destination.txt');
                },
                static function (MysqlMetadataStore $store) use ($path): void {
                    $store->copy('source.txt', $path);
                },
                static function (MysqlMetadataStore $store) use ($path): void {
                    $store->setVisibility($path, 'private');
                },
            ] as $operation
        ) {
            try {
                $operation($this->store);
                Assert::fail('Expected MetadataStoreException for an overlong path.');
            } catch (MetadataStoreException) {
                Assert::assertTrue(true);
            }
        }
    }

    public function testPathAtLimitCanBeWrittenAndRead(): void
    {
        $path = str_repeat('a', 1023);

        $this->store->write(new FileMetadata($path, TelegramType::DOCUMENT, 1, null, null, 100, 'limit', null, '-100', 1));

        Assert::assertTrue($this->store->fileExists($path));
        Assert::assertSame('limit', $this->store->read($path)?->metadata->telegramFileId);
    }

    public function testPathsSharingFirst191CharactersCanCoexist(): void
    {
        $prefix = str_repeat('a', 191);
        $first = $prefix . 'x.txt';
        $second = $prefix . 'y.txt';

        $this->store->write(new FileMetadata($first, TelegramType::DOCUMENT, 1, null, null, 100, 'first', null, '-100', 1));
        $this->store->write(new FileMetadata($second, TelegramType::DOCUMENT, 1, null, null, 100, 'second', null, '-100', 2));

        Assert::assertTrue($this->store->fileExists($first));
        Assert::assertTrue($this->store->fileExists($second));
        Assert::assertSame('first', $this->store->read($first)?->metadata->telegramFileId);
        Assert::assertSame('second', $this->store->read($second)?->metadata->telegramFileId);
    }

    public function testInvalidTablePrefixThrowsMetadataStoreException(): void
    {
        $this->expectException(MetadataStoreException::class);

        new MysqlMetadataStore(pdo: $this->pdo, tablePrefix: 'bad-prefix;drop');
    }

    public function testExplicitDsnConstructionWorks(): void
    {
        $dsn = getenv('FLYSYSTEM_TELEGRAM_DB_DSN');
        Assert::assertIsString($dsn);

        $username = getenv('FLYSYSTEM_TELEGRAM_DB_USER');
        $password = getenv('FLYSYSTEM_TELEGRAM_DB_PASS');
        $prefix = 'fly_' . bin2hex(random_bytes(4)) . '_';
        $store = new MysqlMetadataStore(
            dsn: $dsn,
            username: $username === false ? null : $username,
            password: $password === false ? null : $password,
            tablePrefix: $prefix,
        );

        try {
            $store->write(new FileMetadata('dsn.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'file', null, '-100', 1));
            Assert::assertTrue($store->fileExists('dsn.txt'));
        } finally {
            $this->dropPrefixTables($prefix);
        }
    }

    public function testDsnConstructionUsesEnvironmentFallback(): void
    {
        $prefix = 'fly_' . bin2hex(random_bytes(4)) . '_';
        $store = new MysqlMetadataStore(tablePrefix: $prefix);

        try {
            $store->write(new FileMetadata('env.txt', TelegramType::DOCUMENT, 1, null, null, 100, 'file', null, '-100', 1));
            Assert::assertTrue($store->fileExists('env.txt'));
        } finally {
            $this->dropPrefixTables($prefix);
        }
    }

    public function testSchemaInitializationRecordsVersionOne(): void
    {
        $statement = $this->pdo->query('SELECT version FROM `' . $this->tablePrefix . 'schema_migrations` ORDER BY version ASC');
        Assert::assertNotFalse($statement);

        Assert::assertSame([1], array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testSchemaInitializationIsIdempotent(): void
    {
        new MysqlMetadataStore(pdo: $this->pdo, tablePrefix: $this->tablePrefix);
        new MysqlMetadataStore(pdo: $this->pdo, tablePrefix: $this->tablePrefix);

        $statement = $this->pdo->query('SELECT version FROM `' . $this->tablePrefix . 'schema_migrations` ORDER BY version ASC');
        Assert::assertNotFalse($statement);

        Assert::assertSame([1], array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function dropPrefixTables(string $prefix): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS `' . $prefix . 'chunks`');
        $this->pdo->exec('DROP TABLE IF EXISTS `' . $prefix . 'files`');
        $this->pdo->exec('DROP TABLE IF EXISTS `' . $prefix . 'schema_migrations`');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

- [ ] **Step 2: Run MySQL tests without environment variables**

Run:

```bash
vendor/bin/phpunit tests/Integration/Metadata/MysqlMetadataStoreTest.php
```

Expected: tests are skipped with the message `Set FLYSYSTEM_TELEGRAM_DB_DSN to run MySQL metadata store tests.`

- [ ] **Step 3: Run MySQL tests with environment variables when MySQL is available**

Run only when a disposable MySQL/MariaDB database is available:

```bash
FLYSYSTEM_TELEGRAM_DB_DSN="mysql:host=127.0.0.1;dbname=test;charset=utf8mb4" \
FLYSYSTEM_TELEGRAM_DB_USER="root" \
FLYSYSTEM_TELEGRAM_DB_PASS="" \
vendor/bin/phpunit tests/Integration/Metadata/MysqlMetadataStoreTest.php
```

Expected before implementation: FAIL because `Aahl\FlysystemTelegram\Metadata\MysqlMetadataStore` does not exist yet. If MySQL is not available locally, this pre-implementation failure check may be skipped.

- [ ] **Step 4: Commit failing MySQL tests**

```bash
git add tests/Integration/Metadata/MysqlMetadataStoreTest.php
git commit -m "$(cat <<'EOF'
test: describe MySQL metadata store behavior

Generated with [Claude Code](https://claude.ai/code)
via [Happy](https://happy.engineering)

Co-Authored-By: Claude <noreply@anthropic.com>
Co-Authored-By: Happy <yesreply@happy.engineering>
EOF
)"
```

---

### Task 5: Implement MysqlMetadataStore

**Files:**
- Create: `src/Metadata/MysqlMetadataStore.php`

- [ ] **Step 1: Create the implementation file**

Create `src/Metadata/MysqlMetadataStore.php` with this content:

```php
<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Metadata;

use Aahl\FlysystemTelegram\Exception\MetadataStoreException;
use PDO;
use PDOException;
use Throwable;

final class MysqlMetadataStore implements MetadataStore
{
    private const MAX_PATH_LENGTH = 1023;
    private const TABLE_PREFIX_PATTERN = '/^[A-Za-z0-9_]{0,32}$/';

    private PDO $pdo;

    /**
     * @param array<int, mixed> $options
     */
    public function __construct(
        ?PDO $pdo = null,
        ?string $dsn = null,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        private readonly string $tablePrefix = '',
        bool $autoInitialize = true,
    ) {
        $this->validateTablePrefix($tablePrefix);

        try {
            $this->pdo = $pdo ?? $this->createPdo($dsn, $username, $password, $options);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if ($autoInitialize) {
                $this->initialize();
            }
        } catch (PDOException $exception) {
            throw new MetadataStoreException('Unable to initialize MySQL metadata store.', 0, $exception);
        }
    }

    /**
     * @param list<ChunkMetadata> $chunks
     */
    public function write(FileMetadata $file, array $chunks = []): void
    {
        $this->validatePath($file->path);

        $this->transaction('write metadata', function () use ($file, $chunks): void {
            $this->pdo->prepare('DELETE FROM ' . $this->table('files') . ' WHERE path_hash = :path_hash AND path = :path')
                ->execute([
                    'path_hash' => $this->pathHash($file->path),
                    'path' => $file->path,
                ]);

            $now = time();
            $fileId = $this->insertFile($file, $now);
            $this->insertChunks($fileId, $chunks, $now);
        });
    }

    public function read(string $path): ?StoredFile
    {
        $this->validatePath($path);

        try {
            $statement = $this->pdo->prepare('SELECT * FROM ' . $this->table('files') . ' WHERE path_hash = :path_hash AND path = :path');
            $statement->execute([
                'path_hash' => $this->pathHash($path),
                'path' => $path,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return null;
            }

            $chunkStatement = $this->pdo->prepare('SELECT * FROM ' . $this->table('chunks') . ' WHERE file_id = :file_id ORDER BY chunk_index ASC');
            $chunkStatement->execute(['file_id' => (int) $row['id']]);
            $chunks = [];

            foreach ($chunkStatement->fetchAll(PDO::FETCH_ASSOC) as $chunkRow) {
                $chunks[] = $this->hydrateChunk($path, $chunkRow);
            }

            return new StoredFile($this->hydrateFile($row), $chunks);
        } catch (PDOException $exception) {
            throw new MetadataStoreException('Unable to read metadata.', 0, $exception);
        }
    }

    public function delete(string $path): void
    {
        $this->validatePath($path);

        $this->transaction('delete metadata', function () use ($path): void {
            $this->pdo->prepare('DELETE FROM ' . $this->table('files') . ' WHERE path_hash = :path_hash AND path = :path')
                ->execute([
                    'path_hash' => $this->pathHash($path),
                    'path' => $path,
                ]);
        });
    }

    public function fileExists(string $path): bool
    {
        $this->validatePath($path);

        try {
            $statement = $this->pdo->prepare('SELECT 1 FROM ' . $this->table('files') . ' WHERE path_hash = :path_hash AND path = :path LIMIT 1');
            $statement->execute([
                'path_hash' => $this->pathHash($path),
                'path' => $path,
            ]);

            return $statement->fetchColumn() !== false;
        } catch (PDOException $exception) {
            throw new MetadataStoreException('Unable to check metadata existence.', 0, $exception);
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $this->validatePath($path);

        try {
            $prefix = trim($path, '/');
            $like = $prefix === '' ? '%' : $prefix . '/%';
            $statement = $this->pdo->prepare('SELECT * FROM ' . $this->table('files') . ' WHERE path LIKE :prefix ORDER BY path ASC');
            $statement->execute(['prefix' => $like]);

            $directories = [];

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $file = $this->hydrateFile($row);
                $relative = $prefix === '' ? $file->path : substr($file->path, strlen($prefix) + 1);

                if ($relative === '') {
                    continue;
                }

                $segments = explode('/', $relative);

                if (count($segments) === 1) {
                    yield $file;
                    continue;
                }

                $directoryPath = $prefix === '' ? '' : $prefix;
                $lastDirectoryIndex = $deep ? count($segments) - 2 : 0;

                for ($index = 0; $index <= $lastDirectoryIndex; $index++) {
                    $directoryPath = $directoryPath === '' ? $segments[$index] : $directoryPath . '/' . $segments[$index];

                    if (!isset($directories[$directoryPath])) {
                        $directories[$directoryPath] = true;
                        yield new DirectoryMetadata($directoryPath);
                    }
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
        $this->validatePath($source);
        $this->validatePath($destination);

        $this->transaction('move metadata', function () use ($source, $destination): void {
            $statement = $this->pdo->prepare('UPDATE ' . $this->table('files') . ' SET path = :destination, path_hash = :destination_hash, updated_at = :updated_at WHERE path_hash = :source_hash AND path = :source');
            $statement->execute([
                'destination' => $destination,
                'destination_hash' => $this->pathHash($destination),
                'source_hash' => $this->pathHash($source),
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
        $this->validatePath($source);
        $this->validatePath($destination);

        $this->transaction('copy metadata', function () use ($source, $destination): void {
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

            $fileId = $this->insertFile($file, $now);
            $this->insertChunks($fileId, $chunks, $now);
        });
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->validatePath($path);

        $this->transaction('set metadata visibility', function () use ($path, $visibility): void {
            $statement = $this->pdo->prepare('UPDATE ' . $this->table('files') . ' SET visibility = :visibility, updated_at = :updated_at WHERE path_hash = :path_hash AND path = :path');
            $statement->execute([
                'path' => $path,
                'path_hash' => $this->pathHash($path),
                'visibility' => $visibility,
                'updated_at' => time(),
            ]);

            if ($statement->rowCount() !== 1) {
                throw new MetadataStoreException(sprintf('Path "%s" does not exist.', $path));
            }
        });
    }

    /**
     * @param array<int, mixed> $options
     */
    private function createPdo(?string $dsn, ?string $username, ?string $password, array $options): PDO
    {
        $resolvedDsn = $this->resolveRequiredDsn($dsn);
        $resolvedUsername = $username ?? $this->env('FLYSYSTEM_TELEGRAM_DB_USER');
        $resolvedPassword = $password ?? $this->env('FLYSYSTEM_TELEGRAM_DB_PASS');

        return new PDO($resolvedDsn, $resolvedUsername, $resolvedPassword, $options);
    }

    private function resolveRequiredDsn(?string $dsn): string
    {
        $resolved = $dsn ?? $this->env('FLYSYSTEM_TELEGRAM_DB_DSN');

        if ($resolved === null || $resolved === '') {
            throw new MetadataStoreException('A PDO instance or MySQL DSN is required.');
        }

        return $resolved;
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }

    private function initialize(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table('files') . ' (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, path VARCHAR(1023) NOT NULL, path_hash BINARY(32) NOT NULL, type VARCHAR(32) NOT NULL, size BIGINT UNSIGNED NOT NULL, mime_type VARCHAR(255) NULL, visibility VARCHAR(32) NULL, last_modified INT UNSIGNED NOT NULL, telegram_file_id VARCHAR(255) NULL, telegram_file_unique_id VARCHAR(255) NULL, telegram_chat_id VARCHAR(255) NOT NULL, telegram_message_id BIGINT NULL, is_chunked TINYINT(1) NOT NULL DEFAULT 0, chunk_size BIGINT UNSIGNED NULL, chunk_count INT UNSIGNED NULL, created_at INT UNSIGNED NOT NULL, updated_at INT UNSIGNED NOT NULL, PRIMARY KEY (id), UNIQUE KEY ' . $this->identifier('files_path_hash_unique') . ' (path_hash), KEY ' . $this->identifier('files_path_prefix_idx') . ' (path(191))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table('chunks') . ' (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, file_id BIGINT UNSIGNED NOT NULL, chunk_index INT UNSIGNED NOT NULL, type VARCHAR(32) NOT NULL, size BIGINT UNSIGNED NOT NULL, telegram_file_id VARCHAR(255) NOT NULL, telegram_file_unique_id VARCHAR(255) NULL, telegram_chat_id VARCHAR(255) NOT NULL, telegram_message_id BIGINT NULL, created_at INT UNSIGNED NOT NULL, updated_at INT UNSIGNED NOT NULL, PRIMARY KEY (id), UNIQUE KEY ' . $this->identifier('chunks_file_index_unique') . ' (file_id, chunk_index), CONSTRAINT ' . $this->identifier('chunks_file_foreign') . ' FOREIGN KEY (file_id) REFERENCES ' . $this->table('files') . ' (id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table('schema_migrations') . ' (version INT UNSIGNED NOT NULL, applied_at INT UNSIGNED NOT NULL, PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->prepare('INSERT IGNORE INTO ' . $this->table('schema_migrations') . ' (version, applied_at) VALUES (1, :applied_at)')
            ->execute(['applied_at' => time()]);
    }

    private function transaction(string $operation, callable $callback): void
    {
        try {
            $this->pdo->beginTransaction();
            $callback();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($exception instanceof MetadataStoreException) {
                throw $exception;
            }

            throw new MetadataStoreException('Unable to ' . $operation . '.', 0, $exception);
        }
    }

    private function insertFile(FileMetadata $file, int $now): int
    {
        $this->pdo->prepare('INSERT INTO ' . $this->table('files') . ' (path, path_hash, type, size, mime_type, visibility, last_modified, telegram_file_id, telegram_file_unique_id, telegram_chat_id, telegram_message_id, is_chunked, chunk_size, chunk_count, created_at, updated_at) VALUES (:path, :path_hash, :type, :size, :mime_type, :visibility, :last_modified, :telegram_file_id, :telegram_file_unique_id, :telegram_chat_id, :telegram_message_id, :is_chunked, :chunk_size, :chunk_count, :created_at, :updated_at)')
            ->execute([
                'path' => $file->path,
                'path_hash' => $this->pathHash($file->path),
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

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<ChunkMetadata> $chunks
     */
    private function insertChunks(int $fileId, array $chunks, int $now): void
    {
        foreach ($chunks as $chunk) {
            $this->pdo->prepare('INSERT INTO ' . $this->table('chunks') . ' (file_id, chunk_index, type, size, telegram_file_id, telegram_file_unique_id, telegram_chat_id, telegram_message_id, created_at, updated_at) VALUES (:file_id, :chunk_index, :type, :size, :telegram_file_id, :telegram_file_unique_id, :telegram_chat_id, :telegram_message_id, :created_at, :updated_at)')
                ->execute([
                    'file_id' => $fileId,
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
    private function hydrateChunk(string $path, array $row): ChunkMetadata
    {
        return new ChunkMetadata(
            $path,
            (int) $row['chunk_index'],
            (string) $row['type'],
            (int) $row['size'],
            (string) $row['telegram_file_id'],
            $row['telegram_file_unique_id'] === null ? null : (string) $row['telegram_file_unique_id'],
            (string) $row['telegram_chat_id'],
            $row['telegram_message_id'] === null ? null : (int) $row['telegram_message_id'],
        );
    }

    private function validateTablePrefix(string $tablePrefix): void
    {
        if (preg_match(self::TABLE_PREFIX_PATTERN, $tablePrefix) !== 1) {
            throw new MetadataStoreException('MySQL table prefix may only contain letters, numbers, and underscores, and must be 32 characters or fewer.');
        }
    }

    private function validatePath(string $path): void
    {
        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new MetadataStoreException(sprintf('Path "%s" exceeds the MySQL metadata store limit of %d bytes.', $path, self::MAX_PATH_LENGTH));
        }
    }

    private function pathHash(string $path): string
    {
        return hash('sha256', $path, true);
    }

    private function table(string $suffix): string
    {
        return $this->identifier($suffix);
    }

    private function identifier(string $suffix): string
    {
        return '`' . $this->tablePrefix . $suffix . '`';
    }
}
```

- [ ] **Step 2: Run MySQL integration tests when MySQL is available**

Run only when a disposable MySQL/MariaDB database is available:

```bash
FLYSYSTEM_TELEGRAM_DB_DSN="mysql:host=127.0.0.1;dbname=test;charset=utf8mb4" \
FLYSYSTEM_TELEGRAM_DB_USER="root" \
FLYSYSTEM_TELEGRAM_DB_PASS="" \
vendor/bin/phpunit tests/Integration/Metadata/MysqlMetadataStoreTest.php
```

Expected after implementation: PASS.

- [ ] **Step 3: Run all tests without MySQL environment variables**

Run:

```bash
composer test
```

Expected: SQLite, adapter, stream, config, and upload tests pass when `ext-pdo_sqlite` is installed; MySQL tests are skipped if `FLYSYSTEM_TELEGRAM_DB_DSN` is not set.

- [ ] **Step 4: Run static analysis**

Run:

```bash
composer analyse
```

Expected: PHPStan passes with no errors.

- [ ] **Step 5: Commit MySQL store implementation**

```bash
git add src/Metadata/MysqlMetadataStore.php tests/Integration/Metadata/MysqlMetadataStoreTest.php
git commit -m "$(cat <<'EOF'
feat: add MySQL metadata store

Generated with [Claude Code](https://claude.ai/code)
via [Happy](https://happy.engineering)

Co-Authored-By: Claude <noreply@anthropic.com>
Co-Authored-By: Happy <yesreply@happy.engineering>
EOF
)"
```

---

### Task 6: Update README Documentation

**Files:**
- Modify: `README.md:13-18`
- Modify: `README.md:146-170`
- Modify: `README.md:180-187`

- [ ] **Step 1: Update requirements section**

Replace the current `## Requirements` list with:

```md
## Requirements

- PHP 8.1+
- `ext-pdo`
- One metadata driver extension:
  - `ext-pdo_sqlite` for the default SQLite metadata store
  - `ext-pdo_mysql` for `MysqlMetadataStore`
- A Telegram Bot token
- A Telegram chat id where the bot can send messages
```

- [ ] **Step 2: Clarify databasePath scope**

Replace this paragraph:

```md
`databasePath` is not read from environment variables. If omitted, it defaults to `.flysystem-telegram.sqlite` in the current working directory.
```

with:

```md
`databasePath` is only used by the default SQLite metadata store. It is not read from environment variables. If omitted, it defaults to `.flysystem-telegram.sqlite` in the current working directory.
```

- [ ] **Step 3: Expand the MetadataStore section**

Replace the entire `## MetadataStore` section with:

````md
## MetadataStore

SQLite is used by default and requires `ext-pdo_sqlite`:

```php
use Aahl\FlysystemTelegram\Metadata\SqliteMetadataStore;

$metadataStore = new SqliteMetadataStore(__DIR__ . '/flysystem-telegram.sqlite');
```

If your application cannot install SQLite support, pass a custom `MetadataStore` or use `MysqlMetadataStore`.

### MySQL / MariaDB

Use MySQL/MariaDB when you do not want the default local SQLite metadata file. This store requires `ext-pdo_mysql`.

```php
use Aahl\FlysystemTelegram\Config\TelegramAdapterConfig;
use Aahl\FlysystemTelegram\Metadata\MysqlMetadataStore;
use Aahl\FlysystemTelegram\TelegramAdapter;

$config = new TelegramAdapterConfig(
    botToken: 'token',
    chatId: '-1001234567890',
);

$metadataStore = new MysqlMetadataStore(
    dsn: 'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    username: 'app',
    password: 'secret',
    tablePrefix: 'flysystem_',
);

$adapter = new TelegramAdapter($config, metadataStore: $metadataStore);
```

You can also pass an existing PDO connection:

```php
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    'app',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$metadataStore = new MysqlMetadataStore(
    pdo: $pdo,
    tablePrefix: 'flysystem_',
);
```

`MysqlMetadataStore` creates its tables by default. Disable automatic initialization when your framework owns database migrations:

```php
$metadataStore = new MysqlMetadataStore(
    pdo: $pdo,
    tablePrefix: 'flysystem_',
    autoInitialize: false,
);
```

When explicit DSN credentials are omitted, `MysqlMetadataStore` can read them from environment variables:

```bash
export FLYSYSTEM_TELEGRAM_DB_DSN="mysql:host=127.0.0.1;dbname=app;charset=utf8mb4"
export FLYSYSTEM_TELEGRAM_DB_USER="app"
export FLYSYSTEM_TELEGRAM_DB_PASS="secret"
```

MySQL/MariaDB metadata paths support up to 1023 bytes.

### Custom stores

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
````

- [ ] **Step 4: Add MySQL integration test environment variables to Development**

After the existing development commands, add:

````md
Run MySQL/MariaDB metadata store integration tests by providing a disposable database:

```bash
export FLYSYSTEM_TELEGRAM_DB_DSN="mysql:host=127.0.0.1;dbname=test;charset=utf8mb4"
export FLYSYSTEM_TELEGRAM_DB_USER="root"
export FLYSYSTEM_TELEGRAM_DB_PASS=""

composer test
```
````

- [ ] **Step 5: Run README-adjacent validation**

Run:

```bash
composer validate
composer test
```

Expected: Composer metadata validates and tests pass when `ext-pdo_sqlite` is installed, with MySQL tests skipped when DB environment variables are not set.

- [ ] **Step 6: Commit README updates**

```bash
git add README.md
git commit -m "$(cat <<'EOF'
docs: document MySQL metadata store

Generated with [Claude Code](https://claude.ai/code)
via [Happy](https://happy.engineering)

Co-Authored-By: Claude <noreply@anthropic.com>
Co-Authored-By: Happy <yesreply@happy.engineering>
EOF
)"
```

---

### Task 7: Final Verification

**Files:**
- Verify: `composer.json`
- Verify: `src/Metadata/MysqlMetadataStore.php`
- Verify: `tests/Contract/MetadataStoreContractTestTrait.php`
- Verify: `tests/Integration/Metadata/SqliteMetadataStoreTest.php`
- Verify: `tests/Integration/Metadata/MysqlMetadataStoreTest.php`
- Verify: `README.md`

- [ ] **Step 1: Run the full local test suite**

Run:

```bash
composer test
```

Expected: all non-MySQL tests pass when `ext-pdo_sqlite` is installed. MySQL integration tests are skipped unless `FLYSYSTEM_TELEGRAM_DB_DSN` is set.

- [ ] **Step 2: Run MySQL integration tests if a disposable database is available**

Run:

```bash
FLYSYSTEM_TELEGRAM_DB_DSN="mysql:host=127.0.0.1;dbname=test;charset=utf8mb4" \
FLYSYSTEM_TELEGRAM_DB_USER="root" \
FLYSYSTEM_TELEGRAM_DB_PASS="" \
vendor/bin/phpunit tests/Integration/Metadata/MysqlMetadataStoreTest.php
```

Expected: PASS. If no MySQL/MariaDB database is available, record that this optional verification was not run.

- [ ] **Step 3: Run static analysis**

Run:

```bash
composer analyse
```

Expected: PHPStan passes with no errors.

- [ ] **Step 4: Run code style check**

Run:

```bash
composer cs
```

Expected: PHP-CS-Fixer dry run passes. If it reports formatting changes, run `composer cs:fix`, inspect the diff, and rerun `composer cs`.

- [ ] **Step 5: Validate Composer metadata**

Run:

```bash
composer validate
```

Expected: Composer reports valid package metadata.

- [ ] **Step 6: Commit final verification fixes if any files changed**

Only run this commit if style or validation fixes changed files:

```bash
git add composer.json README.md src/Metadata/MysqlMetadataStore.php tests/Contract/MetadataStoreContractTestTrait.php tests/Integration/Metadata/SqliteMetadataStoreTest.php tests/Integration/Metadata/MysqlMetadataStoreTest.php
git commit -m "$(cat <<'EOF'
chore: finalize MySQL metadata store checks

Generated with [Claude Code](https://claude.ai/code)
via [Happy](https://happy.engineering)

Co-Authored-By: Claude <noreply@anthropic.com>
Co-Authored-By: Happy <yesreply@happy.engineering>
EOF
)"
```

- [ ] **Step 7: Summarize verification results**

Report:

```text
Implemented MysqlMetadataStore with PDO/DSN construction, optional env fallback, table prefixes, schema initialization, shared contract tests, and README docs.
Verification:
- composer test: PASS
- composer analyse: PASS
- composer cs: PASS
- composer validate: PASS
- MySQL integration tests: PASS or not run because no disposable MySQL database was available
```
