<?php
namespace Tests\Feature;

use App\Models\{User, PilotCheck, SyncRun, PortalCredential, ParserTask, InternalApiToken, TelegramAccount, PushSubscription};
use App\Services\{ParserJobService, ParserTaskScheduler, WebPushSender};
use Illuminate\Support\Facades\{Artisan, Cache, Crypt, DB, Http};
use Tests\TestCase;

class PilotChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        $this->travelTo(now('UTC')->setDate(2026, 10, 12)->setTime(22, 0));
    }
    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }
    private function admin(): User { return User::create(['status' => 'active', 'role' => 'admin', 'timezone' => 'Asia/Krasnoyarsk']); }
    public function test_access_owner_isolation_and_portal_dates_are_read_only(): void
    {
        $this->getJson('/api/pilot-checks')->assertUnauthorized();
        $basic = User::create(['status' => 'active', 'role' => 'user']);
        $this->actingAs($basic)->getJson('/api/pilot-checks')->assertForbidden();
        $owner = $this->admin();
        $this->actingAs($owner)->postJson('/api/pilot-checks', ['title' => 'Проверка', 'due_on' => '2026-11-01', 'user_id' => $basic->id, 'origin' => 'portal'])->assertCreated();
        $check = PilotCheck::first();
        $this->assertSame($owner->id, $check->user_id);
        $this->assertSame('manual', $check->origin);
        $other = $this->admin();
        $this->actingAs($other)->patchJson('/api/pilot-checks/'.$check->id, ['title' => 'Изменить', 'due_on' => '2026-12-01'])->assertNotFound();
        $this->deleteJson('/api/pilot-checks/'.$check->id)->assertNotFound();
        $portal = PilotCheck::create(['user_id' => $owner->id, 'origin' => 'portal', 'title' => 'Квалификационная проверка', 'due_on' => '2026-11-01']);
        $this->actingAs($owner)->patchJson('/api/pilot-checks/'.$portal->id, ['title' => 'Изменить', 'due_on' => '2026-12-01'])->assertNotFound();
        $this->deleteJson('/api/pilot-checks/'.$portal->id)->assertNotFound();
        $this->patchJson('/api/pilot-checks/'.$check->id, ['title' => 'Новая', 'due_on' => '2026-11-03'])->assertOk();
        $this->deleteJson('/api/pilot-checks/'.$check->id)->assertOk();
        $this->assertDatabaseHas('pilot_checks', ['id' => $portal->id, 'due_on' => '2026-11-01']);
    }
    public function test_only_active_extended_subscription_grants_access_and_reminders_can_be_disabled(): void
    {
        $admin = $this->admin();
        $user = User::create(['status' => 'active', 'role' => 'user']);
        $payment = \App\Models\SubscriptionPayment::create([
            'user_id' => $user->id, 'request_id' => (string) \Illuminate\Support\Str::uuid(), 'tier' => 'basic',
            'amount_kopecks' => 100, 'duration_days' => 30, 'paid_at' => now(), 'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30), 'recorded_by' => $admin->id, 'grants_access' => true,
        ]);
        $this->actingAs($user)->getJson('/api/pilot-checks')->assertForbidden();
        $payment->update(['tier' => 'extended']);
        $this->getJson('/api/pilot-checks')->assertOk();
        $this->getJson('/api/account')->assertJsonFragment(['pilot-checks']);
        $this->patchJson('/api/pilot-checks/settings', ['notifications_enabled' => false])->assertOk();
        $this->assertFalse($user->fresh()->check_notifications_enabled);
        PilotCheck::create(['user_id' => $user->id, 'origin' => 'manual', 'title' => 'Проверка', 'due_on' => now()->addDays(14)->toDateString()]);
        config(['services.telegram_bot.token' => null]);
        Artisan::call('checks:notify');
        $this->assertDatabaseCount('pilot_check_deliveries', 0);
        $payment->update(['canceled_at' => now()]);
        $this->getJson('/api/pilot-checks')->assertForbidden();
        $this->postJson('/api/pilot-checks', ['title' => 'Проверка', 'due_on' => '2026-11-01'])->assertForbidden();
    }

    public function test_snapshot_is_idempotent_preserves_manual_and_rejects_wrong_or_expired_runs(): void
    {
        $user = $this->admin();
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('checks-test'), 'is_active' => true]);
        $manual = PilotCheck::create(['user_id' => $user->id, 'origin' => 'manual', 'title' => 'Своя', 'due_on' => '2026-12-01']);
        $run = SyncRun::create(['user_id' => $user->id, 'source' => 'rossiya_edu', 'trigger' => 'scheduler', 'task_type' => 'checks_refresh', 'status' => 'running', 'started_at' => now(), 'lock_expires_at' => now()->addMinutes(10)]);
        $url = '/api/internal/sync-runs/'.$run->id.'/checks';
        $rows = [['title' => 'Квалификационная проверка', 'aircraft_type' => 'СУ95', 'valid_from' => '2025-10-21', 'due_on' => '2026-10-21']];
        $this->withToken('checks-test')->postJson($url, ['checks' => $rows])->assertOk();
        $this->postJson($url, ['checks' => $rows])->assertOk();
        $this->assertDatabaseCount('pilot_checks', 2);
        $this->postJson($url, ['checks' => [['title' => 'bad', 'due_on' => 'nonsense']]])->assertUnprocessable();
        $this->assertDatabaseHas('pilot_checks', ['origin' => 'portal', 'is_current' => true]);
        $run->update(['lock_expires_at' => now()->subMinute()]);
        $this->postJson($url, ['checks' => []])->assertConflict();
        $this->assertDatabaseHas('pilot_checks', ['id' => $manual->id]);
        $run2 = $run->replicate(); $run2->lock_expires_at = now()->addMinutes(10); $run2->save();
        $this->postJson('/api/internal/sync-runs/'.$run2->id.'/checks', ['checks' => []])->assertOk();
        $this->assertDatabaseHas('pilot_checks', ['origin' => 'portal', 'is_current' => false]);
        $this->assertTrue($manual->fresh()->is_current);
    }
    public function test_checks_tasks_require_capability_and_reschedule_after_24_hours(): void
    {
        $user = $this->admin();
        PortalCredential::create(['user_id' => $user->id, 'portal' => 'rossiya_edu', 'login' => 'test', 'password_encrypted' => Crypt::encryptString('password'), 'status' => 'active']);
        app(ParserTaskScheduler::class)->ensureCheckTasks('rossiya_edu', 'rossiya_edu');
        ParserTask::where('task_type', 'checks_refresh')->update(['priority' => 200]);
        $legacy = app(ParserJobService::class)->claim(['user_id' => $user->id, 'capabilities' => ['typed_tasks_v1']]);
        $this->assertSame('roster_refresh', $legacy['task_type']);
        $job = app(ParserJobService::class)->claim(['user_id' => $user->id, 'capabilities' => ['typed_tasks_v1', 'checks_v1']]);
        $this->assertSame('checks_refresh', $job['task_type']);
        $run = SyncRun::find($job['sync_run_id']);
        $run->update(['status' => 'finished', 'finished_at' => now()]);
        app(ParserTaskScheduler::class)->completeTask($run);
        $this->assertTrue(ParserTask::find($job['task_id'])->next_run_at->equalTo(now()->addHours(24)));
    }
    public function test_reminders_use_local_dates_retry_failures_and_deduplicate_each_recipient(): void
    {
        config(['services.telegram_bot.token' => 'test']);
        $user = $this->admin();
        $user->forceFill(['telegram_notifications_enabled' => true])->save();
        TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 123]);
        $device = PushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://example.test', 'endpoint_hash' => hash('sha256', 'test'), 'keys' => []]);
        $push = $this->mock(WebPushSender::class);
        $push->shouldReceive('configured')->andReturn(true);
        $push->shouldReceive('send')->once()->andReturn('sent');
        $check = PilotCheck::create(['user_id' => $user->id, 'origin' => 'manual', 'title' => '<Новая>', 'due_on' => '2026-10-27']); // 14 days from local October 13
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 503)->push(['ok' => true, 'result' => ['message_id' => 1]])]);
        Artisan::call('checks:notify');
        $this->assertDatabaseCount('pilot_check_deliveries', 2);
        $this->assertSame(1, DB::table('pilot_check_deliveries')->whereNotNull('sent_at')->count());
        Artisan::call('checks:notify');
        Http::assertSentCount(1);
        $this->travel(16)->minutes();
        Artisan::call('checks:notify');
        $this->assertSame(2, DB::table('pilot_check_deliveries')->whereNotNull('sent_at')->count());
        Artisan::call('checks:notify');
        Http::assertSentCount(2);
        $check->update(['due_on' => '2026-12-30']);
        Artisan::call('checks:notify');
        Http::assertSentCount(2);
    }
}
