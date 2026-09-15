<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MonitorBotClient
{
    public function request(string $method, array $payload): void
    {
        $token = config('monitor.token');
        if (! $token) {
            throw new RuntimeException('MONITOR_BOT_TOKEN is not configured.');
        }
        // Never include the request URL, token, response body or chained exception in logs.
        try {
            $response = Http::asJson()->connectTimeout(3)->timeout(10)
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);
            $ok = $response->successful() && $response->json('ok') === true;
        } catch (\Throwable $exception) {
            $ok = false;
        }
        if (! $ok) {
            Log::warning('Monitor Telegram request failed', ['method' => $method]);
            throw new RuntimeException('Monitor Telegram API unavailable or rejected the request.');
        }
    }

    public function send(string $chatId, string $text): void
    {
        // Plain text keeps diagnostic values from being interpreted as Telegram HTML.
        foreach (mb_str_split($text, 3900) as $chunk) {
            $this->request('sendMessage', [
                'chat_id' => $chatId,
                'text' => $chunk,
                'reply_markup' => [
                    'keyboard' => [['Состояние', 'Парсеры'], ['Очередь', 'Ошибки']],
                    'resize_keyboard' => true,
                ],
            ]);
        }
    }
}
