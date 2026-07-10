<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramBotClient
{
    private $baseUrl;

    public function __construct()
    {
        $token = config('services.telegram_bot.token');

        if (! $token) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $this->baseUrl = 'https://api.telegram.org/bot'.$token;
    }

    public function sendMessage(int $chatId, string $text, array $options = []): void
    {
        foreach ($this->splitText($text) as $chunk) {
            $payload = array_filter(array_merge([
                'chat_id' => $chatId,
                'text' => $chunk,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], $options), function ($value) {
                return $value !== null;
            });

            $this->request('sendMessage', $payload);
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];

        if ($text !== null) {
            $payload['text'] = $text;
        }

        $this->request('answerCallbackQuery', $payload);
    }

    private function request(string $method, array $payload): void
    {
        $response = Http::asJson()->post($this->baseUrl.'/'.$method, $payload);
        $data = $response->json() ?: [];

        if (! $response->successful() || ($data['ok'] ?? false) !== true) {
            Log::error('Telegram API request failed', [
                'method' => $method,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new RuntimeException('Telegram API request failed.');
        }

        Log::info('Telegram API request sent', [
            'method' => $method,
            'chat_id' => $payload['chat_id'] ?? null,
        ]);
    }

    private function splitText(string $text): array
    {
        $chunks = [];
        $limit = 3900;

        while (mb_strlen($text) > $limit) {
            $part = mb_substr($text, 0, $limit);
            $break = mb_strrpos($part, "\n");

            if ($break === false || $break < 1000) {
                $break = $limit;
            }

            $chunks[] = trim(mb_substr($text, 0, $break));
            $text = trim(mb_substr($text, $break));
        }

        $chunks[] = $text === '' ? ' ' : $text;

        return $chunks;
    }
}
