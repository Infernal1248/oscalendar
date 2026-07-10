<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBotService $bot, TelegramBotClient $client): JsonResponse
    {
        $this->validateBridgeRequest($request);

        $update = $request->all();

        Log::info('Telegram bridge update received', [
            'bot' => $request->header('X-TG-Bot'),
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
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $actions = $client->pullActions();

        Log::info('Telegram bridge update processed', [
            'update_id' => $update['update_id'] ?? null,
            'actions_count' => count($actions),
            'actions' => array_map(function ($action) {
                return $action['method'] ?? 'unknown';
            }, $actions),
        ]);

        return response()->json([
            'ok' => true,
            'actions' => $actions,
        ]);
    }

    private function validateBridgeRequest(Request $request): void
    {
        if ($request->header('X-TG-Bridge') !== 'vds-poller') {
            Log::warning('Telegram bridge request rejected: invalid bridge header', [
                'x_tg_bridge' => $request->header('X-TG-Bridge'),
                'x_tg_bot' => $request->header('X-TG-Bot'),
                'ip' => $request->ip(),
            ]);

            abort(403, 'Invalid Telegram bridge.');
        }

        $botName = config('services.telegram_bridge.name');
        if ($botName && $request->header('X-TG-Bot') !== $botName) {
            Log::warning('Telegram bridge request rejected: invalid bot name', [
                'expected_bot' => $botName,
                'actual_bot' => $request->header('X-TG-Bot'),
                'ip' => $request->ip(),
            ]);

            abort(403, 'Invalid Telegram bridge bot.');
        }

        $secret = config('services.telegram_bridge.secret');
        if ($secret && $request->header('X-TG-Bridge-Secret') !== $secret) {
            Log::warning('Telegram bridge request rejected: invalid secret', [
                'x_tg_bot' => $request->header('X-TG-Bot'),
                'ip' => $request->ip(),
            ]);

            abort(403, 'Invalid Telegram bridge secret.');
        }
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

        if (! is_string($text) || strpos($text, '/') !== 0) {
            return null;
        }

        return explode(' ', trim($text))[0] ?? null;
    }
}
