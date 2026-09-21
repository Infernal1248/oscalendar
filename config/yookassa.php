<?php

return [
    'enabled' => env('YOOKASSA_ENABLED', false),
    'mode' => env('YOOKASSA_MODE', 'test'),
    'test_user_ids' => array_values(array_filter(array_map('intval', explode(',', env('YOOKASSA_TEST_USER_IDS', ''))))),
    'frontend_url' => env('YOOKASSA_FRONTEND_URL', env('APP_URL')),
    'test' => ['shop_id' => env('YOOKASSA_TEST_SHOP_ID'), 'secret' => env('YOOKASSA_TEST_SECRET_KEY')],
    'live' => ['shop_id' => env('YOOKASSA_SHOP_ID'), 'secret' => env('YOOKASSA_SECRET_KEY')],
];
