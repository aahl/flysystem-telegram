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
