<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\RuntimeHealth;
use Illuminate\Support\Facades\DB;

class MonitorHealthController extends Controller
{
    public function __invoke(RuntimeHealth $runtime)
    {
        try {
            $health = $runtime->health();
            $nodes = DB::table('parser_nodes')->pluck('last_seen_at', 'node_id');
            $ids = config('monitor.node_ids') ?: $nodes->keys()->all();
            $offline = 0;
            foreach ($ids as $id) {
                if (! isset($nodes[$id]) || \Carbon\Carbon::parse($nodes[$id])->lt(now()->subSeconds(config('monitor.offline_seconds')))) $offline++;
            }
            return response()->json(array_merge($health, ['nodes' => count($ids), 'offline_nodes' => $offline,
                'ok' => $health['ok'] && $health['notification_backlog'] === 0 && count($ids) > 0 && $offline === 0]));
        } catch (\Throwable $exception) {
            return response()->json(['ok' => false, 'error' => 'monitoring_unavailable'], 503);
        }
    }
}
