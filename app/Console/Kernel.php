<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule)
    {
        $schedule->command('monitor:check')->everyMinute()->withoutOverlapping(10);
        $schedule->command('push:send')->everyMinute()->withoutOverlapping(10);
        $schedule->command('payments:reconcile')->everyMinute()->withoutOverlapping(5)->runInBackground();
        $schedule->command('payments:notify')->everyMinute()->withoutOverlapping(5)->runInBackground();
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
