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

    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        $results = [];
        $chunks = $this->splitText($text);
        foreach ($chunks as $index => $chunk) {
            $chunkOptions = $index === count($chunks) - 1 ? $options : [];
            $payload = array_filter(array_merge([
                'chat_id' => $chatId,
                'text' => $chunk,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], $chunkOptions), function ($value) {
                return $value !== null;
            });

            $result = $this->request('sendMessage', $payload);
            if ($result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    public function removeInlineKeyboard(int $chatId, int $messageId): void
    {
        $this->request('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];

        if ($text !== null) {
            $payload['text'] = $text;
        }

        $this->request('answerCallbackQuery', $payload);
    }

    private function request(string $method, array $payload): ?array
    {
        try {
            $response = Http::asJson()
                ->post($this->baseUrl.'/'.$method, $payload);
        } catch (\Throwable $exception) {
            Log::error('Telegram API request failed', [
                'method' => $method,
                'chat_id' => $payload['chat_id'] ?? null,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $data = $response->json() ?: [];

        if (! $response->successful() || ($data['ok'] ?? false) !== true) {
            Log::error('Telegram API request failed', [
                'method' => $method,
                'chat_id' => $payload['chat_id'] ?? null,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        }

        Log::info('Telegram API request sent', [
            'method' => $method,
            'chat_id' => $payload['chat_id'] ?? null,
        ]);

        return is_array($data['result'] ?? null) ? $data['result'] : [];
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
