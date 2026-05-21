<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Stream;

use Aahl\FlysystemTelegram\Exception\ChunkedStreamException;
use Aahl\FlysystemTelegram\Metadata\StoredFile;
use Aahl\FlysystemTelegram\Telegram\TelegramClientInterface;
use Throwable;

final class ChunkedReadStreamWrapper
{
    public mixed $context;
    private ?string $token = null;
    private ?TelegramClientInterface $client = null;
    private ?StoredFile $file = null;
    private int $chunkIndex = 0;
    private mixed $currentStream = null;
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $token = basename(parse_url($path, PHP_URL_PATH) ?: '');
        $entry = ChunkedReadStreamRegistry::take($token);

        if ($entry === null) {
            return false;
        }

        $this->token = $token;
        $this->client = $entry['client'];
        $this->file = $entry['file'];

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->client === null || $this->file === null) {
            throw new ChunkedStreamException('Chunked stream is not open.');
        }

        $buffer = '';

        while (strlen($buffer) < $count && !$this->stream_eof()) {
            $this->openCurrentChunkIfNeeded();

            if (!is_resource($this->currentStream)) {
                break;
            }

            $data = fread($this->currentStream, $count - strlen($buffer));

            if ($data === false) {
                throw new ChunkedStreamException('Unable to read chunk stream.');
            }

            if ($data === '') {
                if (feof($this->currentStream)) {
                    fclose($this->currentStream);
                    $this->currentStream = null;
                    $this->chunkIndex++;
                }

                break;
            }

            $buffer .= $data;
            $this->position += strlen($data);

            if (feof($this->currentStream)) {
                fclose($this->currentStream);
                $this->currentStream = null;
                $this->chunkIndex++;
                break;
            }
        }

        return $buffer;
    }

    public function stream_eof(): bool
    {
        if ($this->file === null) {
            return true;
        }

        return $this->chunkIndex >= count($this->file->chunks) && !is_resource($this->currentStream);
    }

    public function stream_close(): void
    {
        if (is_resource($this->currentStream)) {
            fclose($this->currentStream);
        }

        if ($this->token !== null) {
            ChunkedReadStreamRegistry::release($this->token);
        }
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    private function openCurrentChunkIfNeeded(): void
    {
        if (is_resource($this->currentStream)) {
            return;
        }

        if ($this->client === null || $this->file === null || !isset($this->file->chunks[$this->chunkIndex])) {
            return;
        }

        $chunk = $this->file->chunks[$this->chunkIndex];

        try {
            $stream = $this->client->downloadStream($chunk->telegramFileId);
        } catch (Throwable $exception) {
            throw new ChunkedStreamException('Unable to download chunk ' . $chunk->index . '.', 0, $exception);
        }

        if (!is_resource($stream)) {
            throw new ChunkedStreamException('Telegram client did not return a stream for chunk ' . $chunk->index . '.');
        }

        $this->currentStream = $stream;
    }
}
