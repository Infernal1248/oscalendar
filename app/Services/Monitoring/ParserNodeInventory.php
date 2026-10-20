<?php

namespace App\Services\Monitoring;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ParserNodeInventory
{
    public function isReserve(string $id): bool
    {
        return in_array($id, config('monitor.reserve_node_ids', []), true);
    }

    public function snapshot(): Collection
    {
        $registered = DB::table('parser_nodes')->orderBy('node_id')->get()->keyBy('node_id');
        $ids = array_values(array_unique(array_merge(
            config('monitor.node_ids') ?: $registered->keys()->all(),
            config('monitor.reserve_node_ids', [])
        )));
        return collect($ids)->map(function ($id) use ($registered) {
            $node = $registered->get($id);
            return [
                'id' => $id,
                'reserve' => $this->isReserve($id),
                'online' => $node && Carbon::parse($node->last_seen_at)->gte(now()->subSeconds(config('monitor.offline_seconds'))),
                'seen' => $node?->last_seen_at,
                'busy' => $node?->busy_workers ?? 0,
                'max' => $node?->max_workers ?? 0,
                'version' => $node?->version ?? 'неизвестна',
            ];
        });
    }
}
