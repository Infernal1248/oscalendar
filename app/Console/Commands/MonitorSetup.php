<?php

namespace App\Console\Commands;

use App\Services\Telegram\MonitorBotClient;
use Illuminate\Console\Command;

class MonitorSetup extends Command
{
    protected $signature = 'monitor:setup';
    protected $aliases = ['monitor:webhook'];
    protected $description = 'Prepare commands for the separate monitor bot through bot-hub';

    public function handle(MonitorBotClient $bot): int
    {
        $base = rtrim(config('app.url'), '/');
        $checks = [
            'APP_URL must be a valid HTTPS URL.' => filter_var($base, FILTER_VALIDATE_URL) && parse_url($base, PHP_URL_SCHEME) === 'https',
            'MONITOR_BOT_TOKEN must be set and differ from TELEGRAM_BOT_TOKEN.' => config('monitor.token') && config('monitor.token') !== config('services.telegram_bot.token'),
            'MONITOR_ADMIN_IDS must contain numeric Telegram user IDs.' => ! empty(config('monitor.admin_ids')),
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
            $bot->request('setMyCommands', ['commands' => [
                ['command' => 'status', 'description' => 'Состояние системы'],
                ['command' => 'parsers', 'description' => 'VM и загрузка воркеров'],
                ['command' => 'queue', 'description' => 'Очередь и зависшие задачи'],
                ['command' => 'errors', 'description' => 'Последние сбои'],
                ['command' => 'load', 'description' => 'CPU и запросы за последний час'],
                ['command' => 'summary', 'description' => 'Сводка за последние 12 часов'],
                ['command' => 'help', 'description' => 'Справка'],
            ]]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
        $this->info('Monitor bot commands configured through bot-hub.');
        $this->line('Callback: '.$base.'/api/telegram/monitor/webhook');
        $this->line('Register the bot on the VPS: bot-hubctl add oscalendar-monitor --callback '.$base.'/api/telegram/monitor/webhook');
        return self::SUCCESS;
    }
}
