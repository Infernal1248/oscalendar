<?php

namespace App\Providers;

use App\Services\Telegram\TelegramBotClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(TelegramBotClient::class, function () {
            return new TelegramBotClient();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (defined('OSCALENDAR_CPU_START') && config('monitor.metrics_enabled') && config('monitor.token')
            && ! $this->app->runningUnitTests()) {
            $metrics = new \App\Services\Monitoring\ProcessMetrics();
            $events = $this->app->make('events');
            $events->listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, [$metrics, 'request']);
            $events->listen(\Illuminate\Console\Events\CommandFinished::class, [$metrics, 'command']);
            $events->listen(\Illuminate\Log\Events\MessageLogged::class, [$metrics, 'log']);
            register_shutdown_function([$metrics, 'finish']);
        }
    }
}
