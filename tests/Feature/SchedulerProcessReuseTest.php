<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SchedulerProcessReuseTest extends TestCase
{
    private function registerCommand(string $name, \Closure $callback): void
    {
        Artisan::registerCommand(new class($name, $callback) extends Command {
            public function __construct(string $name, private \Closure $callback)
            {
                $this->signature = $name;
                parent::__construct();
            }

            public function handle(): int
            {
                return ($this->callback)();
            }
        });
    }

    private function inlineEvents(): array
    {
        $events = [];
        foreach (app(Schedule::class)->events() as $event) {
            if ($event instanceof CallbackEvent) {
                $events[$event->description] = $event;
            } else {
                // Never launch real notification subprocesses in scheduler tests.
                $event->skip(fn () => true);
            }
        }

        return $events;
    }

    public function test_commands_run_in_the_scheduler_process_at_the_original_frequency(): void
    {
        $pids = [];
        foreach (['monitor:check', 'push:send'] as $name) {
            $this->registerCommand($name, function () use (&$pids, $name) {
                $pids[$name] = getmypid();
                return 0;
            });
        }
        $events = $this->inlineEvents();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(10, $event->expiresAt);
        }

        Artisan::call('schedule:run');

        $this->assertSame(['monitor:check' => getmypid(), 'push:send' => getmypid()], $pids);
    }

    public function test_monitor_failure_is_reported_without_preventing_push_and_releases_its_lock(): void
    {
        Event::fake([ScheduledTaskFailed::class]);
        $pushCalls = 0;
        $this->registerCommand('monitor:check', fn () => 1);
        $this->registerCommand('push:send', function () use (&$pushCalls) {
            $pushCalls++;
            return 0;
        });
        $events = $this->inlineEvents();

        Artisan::call('schedule:run');

        $this->assertSame(1, $pushCalls);
        Event::assertDispatched(ScheduledTaskFailed::class, fn ($event) =>
            str_contains($event->exception->getMessage(), 'monitor:check'));
        $this->assertFalse($events['monitor:check']->mutex->exists($events['monitor:check']));
    }

    public function test_existing_schedule_lock_skips_the_command_without_blocking_other_tasks(): void
    {
        $this->registerCommand('monitor:check', function () {
            $this->fail('A locked monitor task must not run.');
        });
        $pushCalls = 0;
        $this->registerCommand('push:send', function () use (&$pushCalls) {
            $pushCalls++;
            return 0;
        });
        $events = $this->inlineEvents();
        $monitor = $events['monitor:check'];
        $this->assertTrue($monitor->mutex->create($monitor));

        Artisan::call('schedule:run');

        $this->assertSame(1, $pushCalls);
        $monitor->mutex->forget($monitor);
    }

    public function test_monitor_exception_does_not_cancel_push_or_leave_the_schedule_locked(): void
    {
        Event::fake([ScheduledTaskFailed::class]);
        $this->registerCommand('monitor:check', function () {
            throw new \RuntimeException('Monitor temporarily unavailable');
        });
        $pushCalls = 0;
        $this->registerCommand('push:send', function () use (&$pushCalls) {
            $pushCalls++;
            return 0;
        });
        $events = $this->inlineEvents();

        Artisan::call('schedule:run');

        $this->assertSame(1, $pushCalls);
        Event::assertDispatched(ScheduledTaskFailed::class);
        $this->assertFalse($events['monitor:check']->mutex->exists($events['monitor:check']));
    }

    public function test_background_delivery_and_hourly_cleanup_keep_their_schedule(): void
    {
        $events = app(Schedule::class)->events();
        foreach ([
            'checks:notify' => ['*/5 * * * *', true, 10],
            'telegram:notify-roster' => ['* * * * *', true, 10],
            'flight-documents:prune' => ['0 * * * *', false, 60],
        ] as $command => [$expression, $background, $expiry]) {
            $matches = array_values(array_filter($events, fn ($event) =>
                ! ($event instanceof CallbackEvent) && str_contains($event->command, $command)));
            $this->assertCount(1, $matches);
            $this->assertSame($expression, $matches[0]->expression);
            $this->assertSame($background, $matches[0]->runInBackground);
            $this->assertTrue($matches[0]->withoutOverlapping);
            $this->assertSame($expiry, $matches[0]->expiresAt);
        }
    }
}
