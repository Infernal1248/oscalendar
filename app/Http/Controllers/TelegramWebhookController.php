<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBotService $bot, TelegramBotClient $client): JsonResponse
    {
        $this->validateBridgeRequest($request);

        $bot->handle($request->all());

        return response()->json([
            'ok' => true,
            'actions' => $client->pullActions(),
        ]);
    }

    private function validateBridgeRequest(Request $request): void
    {
        if ($request->header('X-TG-Bridge') !== 'vds-poller') {
            abort(403, 'Invalid Telegram bridge.');
        }

        $botName = config('services.telegram_bridge.name');
        if ($botName && $request->header('X-TG-Bot') !== $botName) {
            abort(403, 'Invalid Telegram bridge bot.');
        }

        $secret = config('services.telegram_bridge.secret');
        if ($secret && $request->header('X-TG-Bridge-Secret') !== $secret) {
            abort(403, 'Invalid Telegram bridge secret.');
        }
    }
}
