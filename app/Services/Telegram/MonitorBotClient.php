<?php

namespace App\Services\Telegram;

use RuntimeException;

class MonitorBotClient
{
    public function request(string $method, array $payload): void
    {
        $token = config('monitor.token');
        if (! $token) {
            throw new RuntimeException('MONITOR_BOT_TOKEN is not configured.');
        }
        app(BotHubApi::class)->request($token, $method, $payload);
    }

    public function send(string $chatId, string $text): void
    {
        // Plain text keeps diagnostic values from being interpreted as Telegram HTML.
        foreach (mb_str_split($text, 3900) as $chunk) {
            $this->request('sendMessage', [
                'chat_id' => $chatId,
                'text' => $chunk,
                'reply_markup' => [
                    'keyboard' => [['Состояние', 'Парсеры'], ['Очередь', 'Ошибки'], ['Нагрузка', 'Сводка']],
                    'resize_keyboard' => true,
                ],
            ]);
        }
    }
}
