<?php

return [
    'enabled' => env('YOOKASSA_ENABLED', false),
    'frontend_url' => env('YOOKASSA_FRONTEND_URL', env('APP_URL')),
    'live' => ['shop_id' => env('YOOKASSA_SHOP_ID'), 'secret' => env('YOOKASSA_SECRET_KEY')],
];
