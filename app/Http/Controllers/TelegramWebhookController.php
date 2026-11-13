<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBotService $bot): JsonResponse
    {
        $update = $request->all();

        Log::info('Telegram bridge update received', [
            'update_id' => $update['update_id'] ?? null,
            'type' => $this->updateType($update),
            'chat_id' => $this->chatId($update),
            'telegram_id' => $this->telegramUserId($update),
            'command' => $this->command($update),
        ]);

        try {
            $bot->handle($update);
        } catch (\Throwable $exception) {
            Log::error('Telegram bridge update failed', [
                'update_id' => $update['update_id'] ?? null,
                'type' => $this->updateType($update),
                'chat_id' => $this->chatId($update),
                'telegram_id' => $this->telegramUserId($update),
                'exception' => get_class($exception),
            ]);

            throw $exception;
        }

        Log::info('Telegram bridge update processed', [
            'update_id' => $update['update_id'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
        ]);
    }

    private function updateType(array $update): string
    {
        if (isset($update['message'])) {
            return 'message';
        }

        if (isset($update['callback_query'])) {
            return 'callback_query';
        }

        return 'unknown';
    }

    private function chatId(array $update): ?int
    {
        if (isset($update['message']['chat']['id'])) {
            return (int) $update['message']['chat']['id'];
        }

        if (isset($update['callback_query']['message']['chat']['id'])) {
            return (int) $update['callback_query']['message']['chat']['id'];
        }

        return null;
    }

    private function telegramUserId(array $update): ?int
    {
        if (isset($update['message']['from']['id'])) {
            return (int) $update['message']['from']['id'];
        }

        if (isset($update['callback_query']['from']['id'])) {
            return (int) $update['callback_query']['from']['id'];
        }

        return null;
    }

    private function command(array $update): ?string
    {
        $text = $update['message']['text'] ?? null;

        if (! is_string($text) || ! preg_match('/\A\/(start|help|pending|adduser|approve)(?:\s|$)/', $text, $matches)) {
            return null;
        }

        return '/'.$matches[1];
    }
}
