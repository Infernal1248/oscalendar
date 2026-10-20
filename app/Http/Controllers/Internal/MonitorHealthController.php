<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\RuntimeHealth;
use App\Services\Monitoring\ParserNodeInventory;

class MonitorHealthController extends Controller
{
    public function __invoke(RuntimeHealth $runtime)
    {
        try {
            $health = $runtime->health();
            $nodes = app(ParserNodeInventory::class)->snapshot();
            $required = $nodes->where('reserve', false);
            $reserve = $nodes->where('reserve', true);
            $offline = $required->where('online', false)->count();
            return response()->json(array_merge($health, ['nodes' => $nodes->count(), 'offline_nodes' => $offline,
                'required_nodes' => $required->count(), 'reserve_nodes' => $reserve->count(),
                'reserve_online_nodes' => $reserve->where('online', true)->count(),
                'ok' => $health['ok'] && $health['notification_backlog'] === 0 && $nodes->isNotEmpty() && $offline === 0]));
        } catch (\Throwable $exception) {
            return response()->json(['ok' => false, 'error' => 'monitoring_unavailable'], 503);
        }
    }
}
