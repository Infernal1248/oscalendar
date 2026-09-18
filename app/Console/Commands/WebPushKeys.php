<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class WebPushKeys extends Command
{
    protected $signature = 'push:keys';
    protected $description = 'Generate VAPID keys to save in .env (does not change existing keys)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);
        $this->warn('Сохраните эти ключи в .env. Не публикуйте private key и не меняйте ключи при каждом обновлении.');
        return self::SUCCESS;
    }
}
