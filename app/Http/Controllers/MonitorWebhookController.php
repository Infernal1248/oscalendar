<?php

namespace App\Http\Controllers;

use App\Services\SystemMonitor;
use App\Services\Telegram\MonitorBotClient;
use Illuminate\Http\Request;

class MonitorWebhookController extends Controller
{
    public function __invoke(Request $request, SystemMonitor $monitor, MonitorBotClient $bot)
    {
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
        $text = trim($data['message']['text']);
        $command = [ 'Состояние' => '/status', 'Парсеры' => '/parsers', 'Очередь' => '/queue', 'Ошибки' => '/errors',
            'Нагрузка' => '/load', 'Сводка' => '/summary' ][$text]
            ?? explode('@', explode(' ', $text)[0])[0];
        $bot->send($from, $monitor->report($command));

        return response()->json(['ok' => true]);
    }
}
