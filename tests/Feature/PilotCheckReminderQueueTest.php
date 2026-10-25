<?php

namespace Tests\Feature;

use App\Models\{PilotCheck, PushSubscription, TelegramAccount, User};
use App\Services\{PilotCheckReminderQueue, WebPushSender};
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\{Artisan, DB, Http};
use Tests\TestCase;

class PilotCheckReminderQueueTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12 22:00:00', 'UTC'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'services.telegram_bot.token' => 'test-token']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        $this->mock(WebPushSender::class)->shouldReceive('configured')->andReturn(false);
        $this->user = User::create(['status' => 'active', 'role' => 'admin', 'timezone' => 'Asia/Krasnoyarsk']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function check(array $attributes = []): PilotCheck
    {
        return PilotCheck::create(array_merge(['user_id' => $this->user->id, 'origin' => 'manual',
            'title' => 'Проверка', 'is_current' => true, 'due_on' => '2026-10-27'], $attributes));
    }

    private function scheduled(): bool
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) =>
            $e->command && str_contains($e->command, 'checks:notify'));
        $this->assertNotNull($event);
        return $event->filtersPass($this->app);
    }

    public function test_empty_queue_and_missing_recipients_skip_launch_without_sending_or_creating_deliveries(): void
    {
        $this->assertFalse($this->scheduled());
        $this->check();
        $this->assertFalse($this->scheduled());
        $this->assertDatabaseCount('pilot_check_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_local_calendar_dates_and_each_reminder_interval_are_respected(): void
    {
        TelegramAccount::create(['user_id' => $this->user->id, 'telegram_id' => 111]);
        $check = $this->check();
        foreach ([14, 10, 7, 3] as $days) {
            $check->update(['due_on' => now('Asia/Krasnoyarsk')->addDays($days)->toDateString()]);
            $this->assertTrue($this->scheduled());
        }
        $check->update(['due_on' => now('Asia/Krasnoyarsk')->addDays(13)->toDateString()]);
        $this->assertFalse($this->scheduled());
        $this->assertDatabaseCount('pilot_check_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_successful_telegram_delivery_stops_further_launches_and_edits_create_new_eligibility(): void
    {
        TelegramAccount::create(['user_id' => $this->user->id, 'telegram_id' => 111]);
        $check = $this->check();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->assertTrue($this->scheduled());
        Artisan::call('checks:notify');
        $this->assertFalse($this->scheduled());
        $this->assertSame(1, DB::table('pilot_check_deliveries')->whereNotNull('sent_at')->count());
        Http::assertSentCount(1);
        $check->update(['due_on' => '2026-10-23']); // Ten days in the user's timezone.
        $this->assertTrue($this->scheduled());
    }

    public function test_failures_wait_fifteen_minutes_and_then_retry_without_duplicate_success(): void
    {
        TelegramAccount::create(['user_id' => $this->user->id, 'telegram_id' => 111]);
        $this->check();
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 503)
            ->push(['ok' => true, 'result' => ['message_id' => 1]])]);
        Artisan::call('checks:notify');
        $this->assertFalse($this->scheduled());
        $this->travel(15)->minutes();
        $this->assertTrue($this->scheduled());
        Artisan::call('checks:notify');
        $this->assertFalse($this->scheduled());
        Http::assertSentCount(2);
    }

    public function test_push_only_reminder_can_trigger_launch_without_telegram(): void
    {
        config(['services.telegram_bot.token' => null]);
        $push = $this->mock(WebPushSender::class);
        $push->shouldReceive('configured')->andReturn(true);
        $push->shouldReceive('send')->once()->andReturn('sent');
        PushSubscription::create(['user_id' => $this->user->id, 'endpoint' => 'https://example.test/push',
            'endpoint_hash' => hash('sha256', 'push-test'), 'keys' => []]);
        $this->check();
        $this->assertTrue($this->scheduled());
        Artisan::call('checks:notify');
        $this->assertFalse($this->scheduled());
        $this->assertDatabaseHas('pilot_check_deliveries', ['channel' => 'push', 'attempts' => 1]);
        Http::assertNothingSent();
    }

    public function test_disabled_preference_invisible_checks_and_ineligible_subscription_do_not_trigger(): void
    {
        TelegramAccount::create(['user_id' => $this->user->id, 'telegram_id' => 111]);
        $check = $this->check();
        $this->user->forceFill(['check_notifications_enabled' => false])->save();
        $this->assertFalse($this->scheduled());
        $this->user->forceFill(['check_notifications_enabled' => true])->save();
        $check->update(['origin' => 'portal', 'title' => 'Неподдерживаемая проверка']);
        $this->assertFalse($this->scheduled());
        $check->update(['origin' => 'manual', 'is_current' => false]);
        $this->assertFalse($this->scheduled());
        $check->update(['is_current' => true]);
        $this->user->roles()->detach(); // No admin role and no extended subscription.
        $this->assertFalse($this->scheduled());
    }

    public function test_disabled_channels_skip_database_work(): void
    {
        config(['services.telegram_bot.token' => null]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertFalse($this->scheduled());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $queries);
    }

    public function test_preflight_database_failure_falls_back_to_background_command(): void
    {
        \Illuminate\Support\Facades\Schema::drop('pilot_checks');
        $this->assertTrue($this->scheduled());
        Http::assertNothingSent();
    }

    public function test_preflight_timeout_falls_back_instead_of_suppressing_reminders(): void
    {
        $queue = new class extends PilotCheckReminderQueue {
            public function dueDeliveries(WebPushSender $push, ?float $deadline = null, bool $failOnDeadline = false): \Generator
            {
                throw new \RuntimeException('Preflight time budget exceeded');
                yield;
            }
        };
        $this->assertTrue($queue->shouldRun());
    }

    public function test_expired_worker_budget_stops_scanning_without_creating_delivery_state(): void
    {
        $this->check();
        $this->assertSame([], iterator_to_array(app(PilotCheckReminderQueue::class)
            ->dueDeliveries(app(WebPushSender::class), microtime(true) - 1)));
        $this->assertDatabaseCount('pilot_check_deliveries', 0);
    }
}
