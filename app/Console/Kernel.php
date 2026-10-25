<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use RuntimeException;

class Kernel extends ConsoleKernel
{
    protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule)
    {
        $schedule->command('checks:notify')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground()
            ->when(fn () => app(\App\Services\PilotCheckReminderQueue::class)->shouldRun());
        $schedule->command('flight-reports:schedule')->hourly()->withoutOverlapping(60);
        $schedule->command('flight-reports:prune')->daily()->withoutOverlapping(60);
        $schedule->command('flight-documents:prune')->hourly()->withoutOverlapping(60);
        $schedule->command('telegram:notify-roster')->everyMinute()->withoutOverlapping(10)->runInBackground()
            ->when(fn () => app(\App\Services\Telegram\RosterTelegramQueue::class)->shouldRun());
        // These tasks already run sequentially. Reuse the scheduler's application
        // instead of booting a new PHP/Laravel process for each command.
        foreach (['monitor:check', 'push:send'] as $command) {
            $schedule->call(function () use ($command) {
                $exitCode = $this->call($command);
                if ($exitCode !== 0) {
                    throw new RuntimeException("Scheduled command [{$command}] failed with exit code [{$exitCode}].");
                }
            })->name($command)->everyMinute()->withoutOverlapping(10);
        }
        // Send reports after routine checks/push so Telegram cannot delay them.
        $schedule->call(fn () => app(\App\Services\Monitoring\MonitorDigest::class)->sendDue())
            ->name('monitor:send-digest')->everyMinute()->withoutOverlapping(10);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
