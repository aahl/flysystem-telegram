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

    /**
     * @param list<ChunkMetadata> $chunks
     */
    public function write(FileMetadata $file, array $chunks = []): void
    {
        $this->transaction(function () use ($file, $chunks): void {
            $this->pdo->prepare('DELETE FROM chunks WHERE path = :path')->execute(['path' => $file->path]);
            $this->pdo->prepare('DELETE FROM files WHERE path = :path')->execute(['path' => $file->path]);
            $this->insertFile($file, $chunks, time());
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
        try {
            $prefix = trim($path, '/');
            $like = $prefix === '' ? '%' : $prefix . '/%';
            $statement = $this->pdo->prepare('SELECT * FROM files WHERE path LIKE :prefix ORDER BY path ASC');
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
