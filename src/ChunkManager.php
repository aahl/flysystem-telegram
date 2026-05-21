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
