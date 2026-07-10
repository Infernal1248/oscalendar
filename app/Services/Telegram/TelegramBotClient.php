<?php

namespace App\Services\Telegram;

class TelegramBotClient
{
    private $actions = [];

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

            $this->queueAction('sendMessage', $payload);
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];

        if ($text !== null) {
            $payload['text'] = $text;
        }

        $this->queueAction('answerCallbackQuery', $payload);
    }

    public function pullActions(): array
    {
        $actions = $this->actions;
        $this->actions = [];

        return $actions;
    }

    private function queueAction(string $method, array $payload): void
    {
        $this->actions[] = [
            'method' => $method,
            'payload' => $payload,
        ];
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
