<?php

namespace Tests\Feature;

use App\Models\{InternalApiToken, ParserTask, PortalCredential, PushSubscription, SyncRun, TelegramAccount, User};
use App\Services\{PortalAuthNotifications, SystemMonitor, WebPushSender};
use App\Services\Monitoring\MonitorHistory;
use App\Services\Telegram\MonitorBotClient;
use Illuminate\Support\Facades\{Artisan, DB, Http};
use Tests\TestCase;

class PortalAuthNotificationsTest extends TestCase
{
    private User $user;
    private PortalCredential $credential;
    private const AUTH_ERROR = 'Portal session is still invalid after login';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-29 00:00:00', 'UTC'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'services.telegram_bot.token' => 'user-test-token',
            'monitor.token' => 'admin-test-token', 'monitor.admin_ids' => ['999'], 'monitor.node_ids' => [],
            'monitor.reserve_node_ids' => [], 'yookassa.frontend_url' => 'https://oscalendar.example', 'parser.auth_failure_notify_hours' => 24,
            'parser.auth_failure_min_attempts' => 5, 'parser.auth_failure_max_gap_hours' => 2]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        $this->mock(WebPushSender::class)->shouldReceive('configured')->andReturn(false);
        $this->user = User::create(['status' => 'active']);
        $this->credential = PortalCredential::create(['user_id' => $this->user->id, 'portal' => 'rossiya_edu',
            'login' => 'private-login', 'password_encrypted' => 'private-password', 'status' => 'active']);
        TelegramAccount::create(['user_id' => $this->user->id, 'telegram_id' => 123]);
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('internal-test'), 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function finish(?string $error = self::AUTH_ERROR, string $worker = 'vm-1', $started = null): SyncRun
    {
        $run = SyncRun::create(['user_id' => $this->user->id, 'source' => 'rossiya_edu', 'task_type' => 'roster_refresh',
            'worker_id' => $worker, 'status' => 'running', 'started_at' => $started ?? now()->subSecond()]);
        $this->withToken('internal-test')->postJson('/api/internal/sync-runs/'.$run->id.'/finish', [
            'status' => $error === null ? 'finished' : 'failed', 'error_text' => $error,
        ])->assertOk();
        return $run->fresh();
    }

    private function prolongedFailures(): void
    {
        $this->finish();
        for ($i = 0; $i < 24; $i++) {
            $this->travel(1)->hours();
            $this->finish();
        }
    }

    private function fakeTelegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_short_auth_failures_and_server_errors_never_warn_the_user(): void
    {
        $this->fakeTelegram();
        for ($i = 0; $i < 8; $i++) {
            $this->finish();
            $this->travel(10)->minutes();
        }
        Artisan::call('portal:notify-auth');
        Http::assertNothingSent();
        $this->travel(24)->hours();
        $this->finish('HTTP 503 Server Error');
        Artisan::call('portal:notify-auth');
        Http::assertNothingSent();
        $this->assertNull($this->credential->fresh()->auth_failure_started_at);
    }

    public function test_long_gap_and_non_auth_failure_restart_the_observation_period(): void
    {
        $this->finish();
        $this->travel(25)->hours();
        $this->finish();
        $this->assertSame(1, $this->credential->fresh()->auth_failure_count);
        $this->assertFalse(app(PortalAuthNotifications::class)->due()->exists());
        $this->travel(1)->hours();
        $this->finish('Read timed out');
        $this->travel(1)->hours();
        $this->finish();
        $this->assertSame(1, $this->credential->fresh()->auth_failure_count);
    }

    public function test_day_of_failures_sends_once_to_the_user_not_the_administrator(): void
    {
        $this->fakeTelegram();
        $this->prolongedFailures();
        Artisan::call('portal:notify-auth');
        Artisan::call('portal:notify-auth');
        $this->travel(1)->hours();
        $this->finish();
        Artisan::call('portal:notify-auth');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/botuser-test-token/sendMessage'
            && $r['chat_id'] === 123 && str_contains($r['text'], 'обновите логин и пароль')
            && str_contains($r['text'], 'прежние логин и пароль')
            && str_contains($r['text'], 'тоже будет по новым данным')
            && str_contains($r['text'], '«Сменить пароль»')
            && ($r['reply_markup']['inline_keyboard'][0][0]['url'] ?? '') === 'https://oscalendar.example/profile#opensky'
            && ! str_contains($r['text'], 'private-'));
        $this->assertNotNull($this->credential->fresh()->auth_notified_at);
    }

    public function test_success_on_another_vm_resets_the_incident_and_late_older_attempt_cannot_restart_it(): void
    {
        $this->fakeTelegram();
        $this->prolongedFailures();
        $oldStart = now()->subMinute();
        $this->finish(null, 'vm-4');
        $this->travel(1)->seconds();
        $this->finish(self::AUTH_ERROR, 'vm-1', $oldStart);
        Artisan::call('portal:notify-auth');
        Http::assertNothingSent();
        $this->assertSame(0, $this->credential->fresh()->auth_failure_count);
        $this->travel(1)->seconds();
        $this->finish();
        $this->assertSame(1, $this->credential->fresh()->auth_failure_count);
        $this->assertFalse(app(PortalAuthNotifications::class)->due()->exists());
    }

    public function test_editing_credentials_clears_warning_and_ignores_old_worker_result(): void
    {
        $this->prolongedFailures();
        $oldStart = now()->subMinute();
        $this->credential->refresh()->update(['password_encrypted' => 'new-private-password']);
        $this->travel(1)->seconds();
        $this->finish(self::AUTH_ERROR, 'vm-2', $oldStart);
        $this->assertSame(0, $this->credential->fresh()->auth_failure_count);
        $this->assertFalse(app(PortalAuthNotifications::class)->due()->exists());
    }

    public function test_a_new_incident_after_recovery_can_notify_again(): void
    {
        $this->fakeTelegram();
        $this->prolongedFailures();
        Artisan::call('portal:notify-auth');
        $this->finish(null, 'vm-4');
        $this->travel(1)->minutes();
        $this->prolongedFailures();
        Artisan::call('portal:notify-auth');
        Http::assertSentCount(2);
    }

    public function test_disabled_telegram_uses_push_and_success_deduplicates_all_channels(): void
    {
        $this->fakeTelegram();
        $this->user->forceFill(['telegram_notifications_enabled' => false])->save();
        $push = $this->mock(WebPushSender::class);
        $push->shouldReceive('configured')->andReturn(true);
        $push->shouldReceive('send')->once()->withArgs(fn ($device, $payload) =>
            $device->user_id === $this->user->id && $payload['url'] === '/profile#opensky')->andReturn('sent');
        PushSubscription::create(['user_id' => $this->user->id, 'endpoint' => 'https://example.test/push',
            'endpoint_hash' => hash('sha256', 'push'), 'keys' => []]);
        $this->prolongedFailures();
        Artisan::call('portal:notify-auth');
        Artisan::call('portal:notify-auth');
        Http::assertNothingSent();
        $this->assertNotNull($this->credential->fresh()->auth_notified_at);
    }

    public function test_failed_delivery_waits_an_hour_then_rechecks_recovery_before_retry(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 503)]);
        $this->prolongedFailures();
        Artisan::call('portal:notify-auth');
        Artisan::call('portal:notify-auth');
        Http::assertSentCount(1);
        $this->assertNull($this->credential->fresh()->auth_notified_at);
        $this->travel(1)->hours();
        $this->finish(null, 'vm-4');
        Artisan::call('portal:notify-auth');
        Http::assertSentCount(1);
    }

    public function test_failed_telegram_delivery_falls_back_to_push_without_resending_later(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 503)]);
        $push = $this->mock(WebPushSender::class);
        $push->shouldReceive('configured')->andReturn(true);
        $push->shouldReceive('send')->once()->andReturnUsing(function ($device, $payload) {
            $telegramText = Http::recorded()->last()[0]['text'];
            $this->assertSame($telegramText, $payload['body']);
            $this->assertStringContainsString('прежние логин и пароль', $payload['body']);
            $this->assertStringContainsString('https://oscalendar.example/profile#opensky', $payload['body']);
            return 'sent';
        });
        PushSubscription::create(['user_id' => $this->user->id, 'endpoint' => 'https://example.test/push',
            'endpoint_hash' => hash('sha256', 'fallback'), 'keys' => []]);
        $this->prolongedFailures();
        Artisan::call('portal:notify-auth');
        $this->travel(1)->hours();
        $this->finish();
        Artisan::call('portal:notify-auth');
        Http::assertSentCount(1);
        $this->assertNotNull($this->credential->fresh()->auth_notified_at);
    }

    public function test_failed_delivery_retries_after_an_hour_and_only_marks_successful_delivery_sent(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 503)
            ->push(['ok' => true, 'result' => ['message_id' => 2]])]);
        $this->prolongedFailures();
        Artisan::call('portal:notify-auth');
        $this->travel(1)->hours();
        $this->finish();
        Artisan::call('portal:notify-auth');
        Artisan::call('portal:notify-auth');
        Http::assertSentCount(2);
        $this->assertNotNull($this->credential->fresh()->auth_notified_at);
    }

    public function test_missing_recipients_and_blocked_users_do_not_send_or_mark_notified(): void
    {
        $this->fakeTelegram();
        $this->user->telegramAccounts()->delete();
        $this->prolongedFailures();
        Artisan::call('portal:notify-auth');
        $this->assertNull($this->credential->fresh()->auth_notified_at);
        TelegramAccount::create(['user_id' => $this->user->id, 'telegram_id' => 123]);
        $this->travel(1)->hours();
        $this->user->update(['status' => 'blocked']);
        Artisan::call('portal:notify-auth');
        Http::assertNothingSent();
    }

    public function test_separate_local_login_does_not_receive_portal_login_instructions(): void
    {
        $this->fakeTelegram();
        $this->user->update(['login' => 'separate-local-login']);
        $this->prolongedFailures();
        Artisan::call('portal:notify-auth');
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'прежние логин и пароль')
            || str_contains($r['text'] ?? '', 'тоже будет по новым данным'));
    }

    public function test_duplicate_finish_requests_do_not_count_as_extra_auth_attempts(): void
    {
        $run = $this->finish();
        $this->withToken('internal-test')->postJson('/api/internal/sync-runs/'.$run->id.'/finish', [
            'status' => 'failed', 'error_text' => 'Worker process exited unexpectedly',
        ])->assertOk();
        $this->assertSame(1, $this->credential->fresh()->auth_failure_count);
        $this->assertSame(self::AUTH_ERROR, $run->fresh()->error_text);
    }

    public function test_auth_failures_do_not_trigger_admin_repeated_or_stale_alerts_but_other_failures_do(): void
    {
        $this->fakeTelegram();
        $task = ParserTask::create(['task_key' => 'roster:test', 'user_id' => $this->user->id,
            'task_type' => 'roster_refresh', 'status' => 'scheduled', 'next_run_at' => now()->addMinutes(10),
            'last_success_at' => now()->subDay(), 'last_error_text' => self::AUTH_ERROR]);
        for ($i = 0; $i < 3; $i++) $this->finish();
        $monitor = app(SystemMonitor::class);
        $this->assertSame(0, $monitor->snapshot()['failures']);
        $this->assertTrue($monitor->snapshot()['repeated']->isEmpty());
        $this->assertFalse($monitor->snapshot()['stale_roster']);
        $monitor->checkAlerts(app(MonitorBotClient::class));
        Http::assertNothingSent();
        // Scheduled digests also exclude authentication errors, including historical rows.
        $this->travel(1)->minutes();
        $this->assertStringContainsString('сбоев 0', app(MonitorHistory::class)->summary());
        for ($i = 0; $i < 3; $i++) $this->finish('Backend API returned HTTP 401');
        $task->update(['last_error_text' => 'Backend API returned HTTP 401']);
        $this->assertSame(3, $monitor->snapshot()['failures']);
        $this->assertTrue($monitor->snapshot()['stale_roster']);
        $monitor->checkAlerts(app(MonitorBotClient::class));
        Http::assertSentCount(2);
    }
}
