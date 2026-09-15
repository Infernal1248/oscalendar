<?php

namespace App\Console\Commands;

use App\Services\Telegram\MonitorBotClient;
use Illuminate\Console\Command;

class MonitorWebhook extends Command
{
    protected $signature = 'monitor:webhook';
    protected $description = 'Register the separate monitor bot webhook with Telegram';

    public function handle(MonitorBotClient $bot): int
    {
        $secret = config('monitor.webhook_secret', '');
        $base = rtrim(config('app.url'), '/');
        if (! preg_match('/\A[A-Za-z0-9_-]{32,256}\z/', (string) $secret)
            || ! filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https'
            || ! config('monitor.token') || ! config('monitor.admin_ids')
            || config('monitor.token') === config('services.telegram_bot.token')) {
            $this->error('Configure HTTPS APP_URL, a separate MONITOR_BOT_TOKEN, MONITOR_ADMIN_IDS and MONITOR_WEBHOOK_SECRET (32–256 characters: A-Z, a-z, 0-9, _ or -).');
            return self::FAILURE;
        }
        try {
            $bot->request('setWebhook', [
                'url' => $base.'/api/telegram/monitor/webhook',
                'secret_token' => $secret,
                'allowed_updates' => ['message'],
                'max_connections' => 1,
            ]);
            $bot->request('setMyCommands', ['commands' => [
                ['command' => 'status', 'description' => 'Состояние системы'],
                ['command' => 'parsers', 'description' => 'VM и загрузка воркеров'],
                ['command' => 'queue', 'description' => 'Очередь и зависшие задачи'],
                ['command' => 'errors', 'description' => 'Последние сбои'],
                ['command' => 'help', 'description' => 'Справка'],
            ]]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
        $this->info('Monitor webhook and commands registered. Open the bot and send /start.');
        return self::SUCCESS;
    }
}
