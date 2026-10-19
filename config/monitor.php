<?php

return [
    'token' => env('MONITOR_BOT_TOKEN'),
    'bridge_name' => env('MONITOR_BRIDGE_NAME', 'oscalendar_monitor_bot'),
    'admin_ids' => array_values(array_filter(array_map('trim', explode(',', env('MONITOR_ADMIN_IDS', ''))), fn ($id) => ctype_digit($id) && (int) $id > 0)),
    // An explicit list also detects a VM that has never sent a heartbeat.
    'node_ids' => array_values(array_filter(array_map('trim', explode(',', env('MONITOR_NODE_IDS', ''))))),
    'offline_seconds' => max(120, (int) env('MONITOR_OFFLINE_SECONDS', 300)),
    'queue_delay_minutes' => max(1, (int) env('MONITOR_QUEUE_DELAY_MINUTES', 15)),
    'stale_sync_minutes' => max(1, (int) env('MONITOR_STALE_SYNC_MINUTES', 180)),
    'metrics_enabled' => (bool) env('MONITOR_METRICS_ENABLED', true),
    'metrics_path' => storage_path('app/private/monitor-metrics'),
    'retention_days' => 7,
    'summary_enabled' => (bool) env('MONITOR_SUMMARY_ENABLED', true),
    'summary_times' => array_values(array_filter(array_map('trim', explode(',', env('MONITOR_SUMMARY_TIMES', '08:00,20:00'))),
        fn ($time) => preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $time))),
    'timezone' => 'Asia/Krasnoyarsk',
    'summary_hours' => 12,
    'cron_stale_seconds' => max(180, (int) env('MONITOR_CRON_STALE_SECONDS', 300)),
    'notification_delay_minutes' => max(5, (int) env('MONITOR_NOTIFICATION_DELAY_MINUTES', 10)),
];
