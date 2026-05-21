<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Config;

enum UploadTypeStrategy: string
{
    case Auto = 'auto';
    case DocumentOnly = 'document_only';
}
