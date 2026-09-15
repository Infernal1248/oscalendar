<?php

namespace App\Console\Commands;

use App\Services\SystemMonitor;
use App\Services\Telegram\MonitorBotClient;
use Illuminate\Console\Command;

class MonitorCheck extends Command
{
    protected $signature = 'monitor:check';
    protected $description = 'Send administrator alerts when monitored system conditions change';

    public function handle(SystemMonitor $monitor, MonitorBotClient $bot): int
    {
        if (! config('monitor.token') || ! config('monitor.admin_ids')) {
            $this->line('Monitor is disabled: configure MONITOR_BOT_TOKEN and MONITOR_ADMIN_IDS.');
            return self::SUCCESS;
        }
        $monitor->checkAlerts($bot);
        return self::SUCCESS;
    }
}
