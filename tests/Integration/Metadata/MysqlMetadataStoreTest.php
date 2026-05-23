<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Integration\Metadata;

use Aahl\FlysystemTelegram\Exception\MetadataStoreException;
use Aahl\FlysystemTelegram\Metadata\FileMetadata;
use Aahl\FlysystemTelegram\Metadata\MetadataStore;
use Aahl\FlysystemTelegram\Metadata\MysqlMetadataStore;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\Tests\Contract\MetadataStoreContractTestTrait;
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
