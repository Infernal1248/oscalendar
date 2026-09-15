<?php

namespace App\Console\Commands;

use App\Services\Telegram\MonitorBotClient;
use Illuminate\Console\Command;

class MonitorSetup extends Command
{
    protected $signature = 'monitor:setup';
    protected $aliases = ['monitor:webhook'];
    protected $description = 'Prepare the separate monitor bot for the VDS polling bridge';

    public function handle(MonitorBotClient $bot): int
    {
        $secret = config('monitor.bridge_secret', '');
        $base = rtrim(config('app.url'), '/');
        $checks = [
            'APP_URL must be a valid HTTPS URL.' => filter_var($base, FILTER_VALIDATE_URL) && parse_url($base, PHP_URL_SCHEME) === 'https',
            'MONITOR_BOT_TOKEN must be set and differ from TELEGRAM_BOT_TOKEN.' => config('monitor.token') && config('monitor.token') !== config('services.telegram_bot.token'),
            'MONITOR_ADMIN_IDS must contain numeric Telegram user IDs.' => ! empty(config('monitor.admin_ids')),
            'MONITOR_BRIDGE_NAME must be set.' => is_string(config('monitor.bridge_name')) && trim(config('monitor.bridge_name')) !== '',
            'MONITOR_BRIDGE_SECRET must have 32–256 characters: A-Z, a-z, 0-9, _ or -.' => preg_match('/\A[A-Za-z0-9_-]{32,256}\z/', (string) $secret),
        ];
        foreach ($checks as $message => $valid) {
            if (! $valid) {
                $this->error($message);
            }
        }
        if (in_array(false, array_map('boolval', $checks), true)) {
            return self::FAILURE;
        }
        try {
            // getUpdates cannot work while a Telegram webhook is registered.
            // Keep pending updates so moving to the bridge does not discard commands.
            $bot->request('deleteWebhook', ['drop_pending_updates' => false]);
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
        $this->info('Monitor bot is ready for VDS polling; direct Telegram webhook removed.');
        $this->line('Bridge bot name: '.config('monitor.bridge_name'));
        $this->line('Bridge target: '.$base.'/api/telegram/monitor/webhook');
        $this->line('Configure X-TG-Bridge-Secret on the bridge, then start polling and send /start to the bot.');
        return self::SUCCESS;
    }
}
