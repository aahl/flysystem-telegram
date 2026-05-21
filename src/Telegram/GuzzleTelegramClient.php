<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Telegram;

use Aahl\FlysystemTelegram\Exception\TelegramApiException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

final class GuzzleTelegramClient implements TelegramClientInterface
{
    private ClientInterface $httpClient;

    /**
     * @var array<string, array{method: string, field: string, response_key: string}>
     */
    private const TYPE_MAP = [
        TelegramType::PHOTO => ['method' => 'sendPhoto', 'field' => 'photo', 'response_key' => 'photo'],
        TelegramType::VIDEO => ['method' => 'sendVideo', 'field' => 'video', 'response_key' => 'video'],
        TelegramType::AUDIO => ['method' => 'sendAudio', 'field' => 'audio', 'response_key' => 'audio'],
        TelegramType::ANIMATION => ['method' => 'sendAnimation', 'field' => 'animation', 'response_key' => 'animation'],
        TelegramType::DOCUMENT => ['method' => 'sendDocument', 'field' => 'document', 'response_key' => 'document'],
    ];

    public function __construct(
        private readonly string $botToken,
        ?ClientInterface $httpClient = null,
        private readonly string $apiBaseUri = 'https://api.telegram.org',
        private readonly string $fileBaseUri = 'https://api.telegram.org/file',
        private readonly float $timeout = 30.0,
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    public function upload(TelegramUploadRequest $request): TelegramUploadedFile
    {
        TelegramType::assertValid($request->type);
        $mapping = self::TYPE_MAP[$request->type];

        $multipart = [
            ['name' => 'chat_id', 'contents' => $request->chatId],
            ['name' => $mapping['field'], 'contents' => $request->contents, 'filename' => $request->filename ?? basename($mapping['field'])],
        ];

        if ($request->mimeType !== null) {
            $multipart[] = ['name' => 'mime_type', 'contents' => $request->mimeType];
        }

        if ($request->replyToMessageId !== null) {
            $multipart[] = ['name' => 'reply_to_message_id', 'contents' => (string) $request->replyToMessageId];
        }

        $payload = $this->requestJson('POST', $this->apiUrl($mapping['method']), ['multipart' => $multipart]);
        $result = $payload['result'];
        $file = $this->extractUploadedFile($request->type, $result, $mapping['response_key']);

        return new TelegramUploadedFile(
            $request->type,
            (string) $file['file_id'],
            isset($file['file_unique_id']) ? (string) $file['file_unique_id'] : null,
            (string) $request->chatId,
            isset($result['message_id']) ? (int) $result['message_id'] : null,
            isset($file['file_size']) ? (int) $file['file_size'] : null,
            isset($file['mime_type']) ? (string) $file['mime_type'] : $request->mimeType,
        );
    }

    public function downloadStream(string $fileId): mixed
    {
        $payload = $this->requestJson('POST', $this->apiUrl('getFile'), [
            'form_params' => ['file_id' => $fileId],
        ]);

        if (!isset($payload['result']['file_path'])) {
            throw new TelegramApiException('Telegram getFile response did not include file_path.');
        }

        try {
            $response = $this->httpClient->request('GET', $this->fileUrl((string) $payload['result']['file_path']), [
                'stream' => true,
                'timeout' => $this->timeout,
            ]);
        } catch (GuzzleException $exception) {
            throw new TelegramApiException('Telegram file download failed.', 0, $exception);
        }

        return $response->getBody()->detach();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options + ['timeout' => $this->timeout]);
        } catch (GuzzleException $exception) {
            throw new TelegramApiException('Telegram API request failed.', 0, $exception);
        }

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true);

        if (!is_array($payload)) {
            throw new TelegramApiException('Telegram API returned invalid JSON.');
        }

        if (($payload['ok'] ?? false) !== true) {
            $description = isset($payload['description']) ? (string) $payload['description'] : 'unknown error';
            throw new TelegramApiException('Telegram API error: ' . $description);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function extractUploadedFile(string $type, array $result, string $responseKey): array
    {
        if ($type === TelegramType::PHOTO) {
            $photos = $result[$responseKey] ?? null;

            if (!is_array($photos) || $photos === []) {
                throw new TelegramApiException('Telegram photo response did not include photo sizes.');
            }

            $last = end($photos);

            if (!is_array($last)) {
                throw new TelegramApiException('Telegram photo response was malformed.');
            }

            return $last;
        }

        $file = $result[$responseKey] ?? null;

        if (!is_array($file)) {
            throw new TelegramApiException(sprintf('Telegram %s response did not include uploaded file.', $type));
        }

        return $file;
    }

    private function apiUrl(string $method): string
    {
        return rtrim($this->apiBaseUri, '/') . '/bot' . $this->botToken . '/' . $method;
    }

    private function fileUrl(string $filePath): string
    {
        return rtrim($this->fileBaseUri, '/') . '/bot' . $this->botToken . '/' . ltrim($filePath, '/');
    }
}
