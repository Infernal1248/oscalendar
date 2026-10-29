<?php

return [
    'roster_interval_minutes' => (int) env('PARSER_ROSTER_INTERVAL_MINUTES', 30),
    'retry_interval_minutes' => (int) env('PARSER_RETRY_INTERVAL_MINUTES', 10),
    'max_concurrent_per_user' => (int) env('PARSER_MAX_CONCURRENT_PER_USER', 3),
    'auth_failure_notify_hours' => (int) env('PARSER_AUTH_FAILURE_NOTIFY_HOURS', 24),
    'auth_failure_min_attempts' => (int) env('PARSER_AUTH_FAILURE_MIN_ATTEMPTS', 5),
    'auth_failure_max_gap_hours' => (int) env('PARSER_AUTH_FAILURE_MAX_GAP_HOURS', 2),
];
