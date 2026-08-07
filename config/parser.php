<?php

return [
    'roster_interval_minutes' => (int) env('PARSER_ROSTER_INTERVAL_MINUTES', 60),
    'retry_interval_minutes' => (int) env('PARSER_RETRY_INTERVAL_MINUTES', 10),
    'max_concurrent_per_user' => (int) env('PARSER_MAX_CONCURRENT_PER_USER', 3),
];
