<?php

declare(strict_types=1);

namespace Aahl\FlysystemTelegram\Tests\Integration\Telegram;

use Aahl\FlysystemTelegram\Exception\TelegramApiException;
use Aahl\FlysystemTelegram\Telegram\GuzzleTelegramClient;
use Aahl\FlysystemTelegram\Telegram\TelegramType;
use Aahl\FlysystemTelegram\Telegram\TelegramUploadRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GuzzleTelegramClientTest extends TestCase
{
    public function testUploadMapsTypeToBotApiMethodAndParsesResponse(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode([
                'ok' => true,
                'result' => [
                    'message_id' => 42,
                    'chat' => ['id' => -100],
                    'document' => [
                        'file_id' => 'file-id',
                        'file_unique_id' => 'unique-id',
                        'file_size' => 5,
                        'mime_type' => 'text/plain',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $uploaded = $client->upload(new TelegramUploadRequest(TelegramType::DOCUMENT, '-100', 'hello', 'a.txt', 'text/plain'));

        self::assertSame('file-id', $uploaded->fileId);
        self::assertSame('unique-id', $uploaded->fileUniqueId);
        self::assertSame('-100', $uploaded->chatId);
        self::assertSame(42, $uploaded->messageId);
        self::assertSame(5, $uploaded->size);
        self::assertSame('text/plain', $uploaded->mimeType);
        self::assertSame('/botTOKEN/sendDocument', $history[0]['request']->getUri()->getPath());
        $body = (string) $history[0]['request']->getBody();
        self::assertStringContainsString('name="chat_id"', $body);
        self::assertStringContainsString('-100', $body);
        self::assertStringContainsString('name="document"', $body);
        self::assertStringContainsString('filename="a.txt"', $body);
    }

    public function testTypedUploadsMapToExpectedBotApiMethods(): void
    {
        $cases = [
            [TelegramType::PHOTO, '/botTOKEN/sendPhoto', $this->photoResponse()],
            [TelegramType::VIDEO, '/botTOKEN/sendVideo', $this->typedResponse('video')],
            [TelegramType::AUDIO, '/botTOKEN/sendAudio', $this->typedResponse('audio')],
            [TelegramType::ANIMATION, '/botTOKEN/sendAnimation', $this->typedResponse('animation')],
        ];

        foreach ($cases as [$type, $path, $response]) {
            $history = [];
            $client = $this->client([$response], $history);

            $client->upload(new TelegramUploadRequest($type, '-100', 'bytes', 'file.bin', null));

            self::assertSame($path, $history[0]['request']->getUri()->getPath());
            self::assertStringContainsString('name="' . $type . '"', (string) $history[0]['request']->getBody());
        }
    }

    public function testDownloadStreamUsesGetFileThenFileEndpoint(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode(['ok' => true, 'result' => ['file_path' => 'documents/file.txt']], JSON_THROW_ON_ERROR)),
            new Response(200, [], 'content'),
        ], $history);

        $stream = $client->downloadStream('file-id');

        self::assertIsResource($stream);
        self::assertSame('content', stream_get_contents($stream));
        self::assertSame('/botTOKEN/getFile', $history[0]['request']->getUri()->getPath());
        self::assertSame('/file/botTOKEN/documents/file.txt', $history[1]['request']->getUri()->getPath());
    }

    public function testTelegramOkFalseThrowsException(): void
    {
        $this->expectException(TelegramApiException::class);
        $this->expectExceptionMessage('Telegram API error: bad request');

        $client = $this->client([
            new Response(200, [], json_encode(['ok' => false, 'description' => 'bad request'], JSON_THROW_ON_ERROR)),
        ]);

        $client->upload(new TelegramUploadRequest(TelegramType::DOCUMENT, '-100', 'hello', 'a.txt', 'text/plain'));
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history = []): GuzzleTelegramClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new GuzzleTelegramClient(
            'TOKEN',
            new Client(['handler' => $stack]),
            'https://api.telegram.org',
            'https://api.telegram.org/file',
            30.0,
        );
    }

    private function photoResponse(): Response
    {
        return new Response(200, [], json_encode([
            'ok' => true,
            'result' => [
                'message_id' => 43,
                'chat' => ['id' => -100],
                'photo' => [[
                    'file_id' => 'photo-file',
                    'file_unique_id' => 'photo-unique',
                    'file_size' => 5,
                ]],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function typedResponse(string $key): Response
    {
        return new Response(200, [], json_encode([
            'ok' => true,
            'result' => [
                'message_id' => 44,
                'chat' => ['id' => -100],
                $key => [
                    'file_id' => $key . '-file',
                    'file_unique_id' => $key . '-unique',
                    'file_size' => 5,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
