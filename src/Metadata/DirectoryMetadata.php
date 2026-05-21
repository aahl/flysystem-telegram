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
