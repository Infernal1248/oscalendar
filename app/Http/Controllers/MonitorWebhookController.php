<?php

namespace App\Http\Controllers;

use App\Services\SystemMonitor;
use App\Services\Telegram\MonitorBotClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MonitorWebhookController extends Controller
{
    public function __invoke(Request $request, SystemMonitor $monitor, MonitorBotClient $bot)
    {
        $secret = config('monitor.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        $message = $request->input('message', []);
        $from = (string) data_get($message, 'from.id', '');
        if (! in_array($from, array_map('strval', config('monitor.admin_ids')), true)
            || data_get($message, 'chat.type') !== 'private'
            || (string) data_get($message, 'chat.id') !== $from) {
            return response()->json(['ok' => true]);
        }
        $data = $request->validate(['update_id' => ['required', 'integer', 'min:0'], 'message.text' => ['sometimes', 'string', 'max:4096']]);
        if (! isset($data['message']['text'])) {
            return response()->json(['ok' => true]);
        }
        $key = 'monitor:update:'.$data['update_id'];
        if (! Cache::add($key, true, now()->addDay())) {
            return response()->json(['ok' => true]);
        }
        $text = trim($data['message']['text']);
        $command = [ 'Состояние' => '/status', 'Парсеры' => '/parsers', 'Очередь' => '/queue', 'Ошибки' => '/errors' ][$text]
            ?? explode('@', explode(' ', $text)[0])[0];
        try {
            $bot->send($from, $monitor->report($command));
        } catch (\Throwable $exception) {
            Cache::forget($key);
            // Telegram retries non-2xx responses. Do not expose internal diagnostics.
            return response()->json(['ok' => false], 503);
        }

        return response()->json(['ok' => true]);
    }
}
