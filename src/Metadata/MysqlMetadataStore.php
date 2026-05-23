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
