<?php

namespace Tests\Feature;

use App\Models\InternalApiToken;
use App\Models\ParserTask;
use App\Models\PortalCredential;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\SystemMonitor;
use App\Services\Telegram\MonitorBotClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'monitor.token' => 'monitor-test-token', 'services.telegram_bridge.secret' => str_repeat('s', 32),
            'monitor.bridge_name' => 'oscalendar_monitor_bot',
            'monitor.admin_ids' => ['12345'], 'monitor.node_ids' => [],
            'services.telegram_bot.token' => 'user-bot-token',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Carbon::setTestNow('2026-09-15 12:00:00');
        Http::preventStrayRequests();
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('internal-test'), 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function heartbeat(int $busy = 0): void
    {
        $this->withToken('internal-test')->postJson('/api/internal/parser-nodes/heartbeat', [
            'node_id' => 'vm-1', 'source' => 'rossiya_edu', 'version' => 'abc123',
            'max_workers' => 5, 'busy_workers' => $busy,
        ])->assertOk();
    }

    private function bridgeHeaders(): array
    {
        return ['X-TG-Bridge' => 'vds-poller', 'X-TG-Bot' => 'oscalendar_monitor_bot', 'X-TG-Bridge-Secret' => str_repeat('s', 32)];
    }

    public function test_node_auth_validation_and_idle_liveness_do_not_create_jobs(): void
    {
        $this->postJson('/api/internal/parser-nodes/heartbeat', [])->assertUnauthorized();
        $this->withToken('internal-test')->postJson('/api/internal/parser-nodes/heartbeat', [
            'node_id' => 'bad/id', 'source' => 'rossiya_edu', 'version' => 'v1',
            'max_workers' => 5, 'busy_workers' => 6,
        ])->assertUnprocessable()->assertJsonValidationErrors(['node_id', 'busy_workers']);
        $this->heartbeat();
        $this->assertDatabaseCount('sync_runs', 0);
        $this->assertDatabaseCount('parser_tasks', 0);
        $monitor = app(SystemMonitor::class);
        $this->assertTrue($monitor->snapshot()['nodes'][0]['online']);
        $this->assertStringContainsString('0 / 5', $monitor->report('/parsers'));
        Carbon::setTestNow(now()->addSeconds(301));
        $this->assertFalse($monitor->snapshot()['nodes'][0]['online']);
        $this->assertStringContainsString('неизвестно (VM не на связи)', $monitor->report('/parsers'));
        $this->heartbeat(3);
        $this->assertDatabaseCount('parser_nodes', 1);
        $this->assertStringContainsString('3 / 5', $monitor->report('/parsers'));
    }

    public function test_monitor_is_private_fail_closed_and_uses_only_its_own_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $update = ['update_id' => 1, 'message' => ['from' => ['id' => 12345], 'chat' => ['id' => 12345, 'type' => 'private'], 'text' => '/start']];
        $this->postJson('/api/telegram/monitor/webhook', $update)->assertForbidden();
        $headers = $this->bridgeHeaders();
        $this->postJson('/api/telegram/monitor/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 32)])->assertForbidden();
        foreach (['X-TG-Bridge' => 'wrong', 'X-TG-Bot' => 'oscalendar_bot', 'X-TG-Bridge-Secret' => 'wrong'] as $header => $value) {
            $this->postJson('/api/telegram/monitor/webhook', $update, array_replace($headers, [$header => $value]))->assertForbidden();
            $this->postJson('/api/telegram/monitor/webhook', $update, array_diff_key($headers, [$header => true]))->assertForbidden();
        }
        $stranger = $update;
        $stranger['message']['from']['id'] = 987;
        $this->postJson('/api/telegram/monitor/webhook', $stranger, $headers)->assertOk();
        $group = $update;
        $group['message']['chat'] = ['id' => -1, 'type' => 'group'];
        $this->postJson('/api/telegram/monitor/webhook', $group, $headers)->assertOk();
        Http::assertNothingSent();
        $this->postJson('/api/telegram/monitor/webhook', $update, $headers)->assertOk();
        $this->postJson('/api/telegram/monitor/webhook', $update, $headers)->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/botmonitor-test-token/sendMessage'
            && $r['chat_id'] === '12345' && isset($r['reply_markup']['keyboard']));
    }

    public function test_job_api_rate_limit_does_not_block_monitor_heartbeat(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/internal/parser-jobs/claim')->assertUnauthorized();
        }
        $this->postJson('/api/internal/parser-jobs/claim')->assertStatus(429);
        $this->heartbeat();
    }

    public function test_failed_webhook_reply_is_retryable(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 429)->push(['ok' => true])]);
        $update = ['update_id' => 9, 'message' => ['from' => ['id' => 12345], 'chat' => ['id' => 12345, 'type' => 'private'], 'text' => 'Состояние']];
        $headers = $this->bridgeHeaders();
        $this->postJson('/api/telegram/monitor/webhook', $update, $headers)->assertStatus(503);
        $this->postJson('/api/telegram/monitor/webhook', $update, $headers)->assertOk();
        Http::assertSentCount(2);
    }

    public function test_database_error_returns_a_safe_diagnostic_instead_of_a_healthy_status(): void
    {
        \Illuminate\Support\Facades\Schema::drop('parser_nodes');
        $report = app(SystemMonitor::class)->report('/status');
        $this->assertStringContainsString('БД или таблицы мониторинга недоступны', $report);
        $this->assertStringNotContainsString('SQL', $report);
    }

    public function test_telegram_transport_exception_does_not_expose_token(): void
    {
        Http::fake(fn () => throw new \RuntimeException('https://api.telegram.org/botmonitor-test-token/sendMessage'));
        try {
            app(MonitorBotClient::class)->send('12345', 'test');
            $this->fail('Failed transport must throw a safe exception.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('monitor-test-token', (string) $exception);
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_alerts_are_deduplicated_persistent_and_recover_with_idle_heartbeat(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['monitor.node_ids' => ['vm-1']]);
        $monitor = app(SystemMonitor::class);
        $bot = app(MonitorBotClient::class);
        $monitor->checkAlerts($bot); // Never-seen expected node + all nodes unavailable.
        Http::assertSentCount(2);
        app(SystemMonitor::class)->checkAlerts($bot);
        Http::assertSentCount(2);
        $this->heartbeat();
        $monitor->checkAlerts($bot);
        Http::assertSentCount(4);
        $monitor->checkAlerts($bot);
        Http::assertSentCount(4);
        $this->assertDatabaseHas('monitor_alerts', ['key' => '12345:node:vm-1', 'active' => false]);
    }

    public function test_failed_alert_is_not_marked_sent_and_retries_per_recipient(): void
    {
        config(['monitor.node_ids' => ['vm-1'], 'monitor.admin_ids' => ['12345', '67890']]);
        $failFirstRecipient = true;
        Http::fake(function ($r) use (&$failFirstRecipient) {
            return Http::response(['ok' => ! $failFirstRecipient || $r['chat_id'] === '67890']);
        });
        app(SystemMonitor::class)->checkAlerts(app(MonitorBotClient::class));
        $this->assertDatabaseCount('monitor_alerts', 2);
        $this->assertDatabaseMissing('monitor_alerts', ['key' => '12345:node:vm-1']);
        $failFirstRecipient = false;
        app(SystemMonitor::class)->checkAlerts(app(MonitorBotClient::class));
        Http::assertSentCount(6);
        $this->assertDatabaseCount('monitor_alerts', 4);
    }

    public function test_queue_stale_sync_and_errors_use_real_statuses_without_disclosing_raw_errors(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $user = User::create(['display_name' => 'Test', 'status' => 'active']);
        PortalCredential::create(['user_id' => $user->id, 'portal' => 'rossiya_edu', 'login' => 'x', 'password_encrypted' => 'x', 'status' => 'active']);
        $task = ParserTask::create(['task_key' => 'roster:1', 'user_id' => $user->id, 'task_type' => 'roster_refresh',
            'status' => 'scheduled', 'next_run_at' => now()->subMinutes(20), 'last_success_at' => now()->subHours(4)]);
        ParserTask::create(['task_key' => 'future:1', 'user_id' => $user->id, 'task_type' => 'flight_details',
            'status' => 'scheduled', 'next_run_at' => now()->addHour()]);
        SyncRun::create(['user_id' => $user->id, 'status' => 'running', 'started_at' => now()->subHour(), 'lock_expires_at' => now()->subMinute()]);
        for ($i = 0; $i < 3; $i++) {
            SyncRun::create(['user_id' => $user->id, 'status' => 'failed', 'task_type' => 'roster_refresh',
                'started_at' => now()->subMinutes(3), 'finished_at' => now(), 'error_text' => 'timeout password=secret-token-private']);
        }
        $monitor = app(SystemMonitor::class);
        $s = $monitor->snapshot();
        $this->assertSame(1, $s['due']);
        $this->assertSame(1, $s['stuck']);
        $this->assertCount(1, $s['repeated']);
        $this->assertTrue($s['stale_roster']);
        $this->assertStringNotContainsString('secret-token-private', $monitor->report('/errors'));
        $this->assertStringContainsString('Превышено время ожидания', $monitor->report('/errors'));
        $monitor->checkAlerts(app(MonitorBotClient::class));
        $this->assertDatabaseCount('monitor_alerts', 4);
        $task->update(['next_run_at' => now()->addMinutes(10)]); // Retry must not hide stale sync.
        $this->assertTrue($monitor->snapshot()['stale_roster']);
        $user->update(['status' => 'blocked']);
        $this->assertSame(0, $monitor->snapshot()['due']);
        $this->assertFalse($monitor->snapshot()['stale_roster']);
    }

    public function test_bridge_setup_validates_config_and_removes_only_monitor_webhook_without_dropping_updates(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['app.url' => 'http://localhost']);
        $this->artisan('monitor:setup')->expectsOutputToContain('APP_URL')->assertFailed();
        Http::assertNothingSent();
        config(['app.url' => 'https://oscalendar.example']);
        $this->artisan('monitor:setup')->expectsOutputToContain('https://oscalendar.example/api/telegram/monitor/webhook')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/botmonitor-test-token/deleteWebhook'
            && $r['drop_pending_updates'] === false);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/botmonitor-test-token/setMyCommands');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'botuser-bot-token') || str_ends_with($r->url(), '/setWebhook'));
    }

    public function test_old_setup_command_is_an_alias_and_cannot_reenable_direct_webhook(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['app.url' => 'https://oscalendar.example']);
        $this->artisan('monitor:webhook')->assertSuccessful();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/deleteWebhook'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/setWebhook'));
    }

    public function test_reused_bot_token_fails_before_any_telegram_call(): void
    {
        config(['app.url' => 'https://oscalendar.example', 'monitor.token' => 'user-bot-token']);
        $this->artisan('monitor:setup')->expectsOutputToContain('MONITOR_BOT_TOKEN')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_existing_bridge_without_secret_is_accepted_and_obsolete_monitor_secrets_are_ignored(): void
    {
        config([
            'app.url' => 'https://oscalendar.example',
            'services.telegram_bridge.secret' => null,
            'monitor.bridge_secret' => 'obsolete-config-cache-value',
            'monitor.webhook_secret' => 'obsolete-config-cache-value',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $headers = ['X-TG-Bridge' => 'vds-poller', 'X-TG-Bot' => 'oscalendar_monitor_bot'];
        $update = ['update_id' => 101, 'message' => ['from' => ['id' => 12345], 'chat' => ['id' => 12345, 'type' => 'private'], 'text' => '/start']];
        $this->postJson('/api/telegram/monitor/webhook', $update, $headers)->assertOk();
        Http::assertSentCount(1);
        $this->artisan('monitor:setup')->assertSuccessful();
        $update['update_id']++;
        $update['message']['from']['id'] = 999;
        $this->postJson('/api/telegram/monitor/webhook', $update, $headers)->assertOk();
        Http::assertSentCount(3); // /start plus deleteWebhook and setMyCommands, no stranger reply.
    }

    public function test_shared_secret_matches_ordinary_bot_and_rejection_is_logged_without_secret(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        config(['services.telegram_bridge.secret' => 'existing-shared-secret']);
        $headers = $this->bridgeHeaders();
        $this->postJson('/api/telegram/monitor/webhook', [], $headers)->assertForbidden()
            ->assertJsonPath('reason', 'invalid_bridge_secret');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with('Monitor bridge request rejected', ['reason' => 'invalid_bridge_secret']);
        $headers['X-TG-Bridge-Secret'] = 'existing-shared-secret';
        $this->postJson('/api/telegram/monitor/webhook', [], $headers)->assertOk();
    }
}
