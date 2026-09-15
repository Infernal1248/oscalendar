<?php

return [
    'token' => env('MONITOR_BOT_TOKEN'),
    'webhook_secret' => env('MONITOR_WEBHOOK_SECRET'),
    'admin_ids' => array_values(array_filter(array_map('trim', explode(',', env('MONITOR_ADMIN_IDS', ''))), fn ($id) => ctype_digit($id) && (int) $id > 0)),
    // An explicit list also detects a VM that has never sent a heartbeat.
    'node_ids' => array_values(array_filter(array_map('trim', explode(',', env('MONITOR_NODE_IDS', ''))))),
    'offline_seconds' => max(120, (int) env('MONITOR_OFFLINE_SECONDS', 300)),
    'queue_delay_minutes' => max(1, (int) env('MONITOR_QUEUE_DELAY_MINUTES', 15)),
    'stale_sync_minutes' => max(1, (int) env('MONITOR_STALE_SYNC_MINUTES', 180)),
];
