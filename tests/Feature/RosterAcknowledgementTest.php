<?php

namespace Tests\Feature;

use App\Models\ParserTask;
use App\Models\RosterChangeEvent;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\RosterChangeService;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RosterAcknowledgementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Carbon::setTestNow('2026-09-14 10:00:00');
        config(['services.telegram_bot.token' => 'test-token']);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 501]]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_web_and_telegram_acknowledgements_share_expiration_ownership_and_idempotency(): void
    {
        $user = User::create(['status' => 'active']);
        $other = User::create(['status' => 'active']);
        TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 1001]);
        $old = RosterChangeEvent::create(['user_id' => $user->id, 'source' => 'rossiya_edu', 'period' => '2026-08', 'change_hash' => str_repeat('a', 64), 'status' => 'pending', 'changes' => []]);
        $event = app(RosterChangeService::class)->recordPending($user->id, 'rossiya_edu', '2026-09', ['requires_acknowledgement' => true], []);
        $foreign = app(RosterChangeService::class)->recordPending($other->id, 'rossiya_edu', '2026-09', ['requires_acknowledgement' => true], []);
        $this->actingAs($user)->getJson('/api/change-history/pending-count')->assertOk()->assertJsonPath('count', 1);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->postJson("/api/change-history/{$old->id}/acknowledge")->assertConflict();
        $this->postJson("/api/change-history/{$foreign->id}/acknowledge")->assertNotFound();
        app(TelegramBotService::class)->handle(['callback_query' => ['id' => 'old', 'from' => ['id' => 1001], 'data' => 'roster.ack:'.$old->id]]);
        $this->assertDatabaseCount('parser_tasks', 0);
        $this->postJson("/api/change-history/{$event->id}/acknowledge")->assertStatus(202);
        $this->postJson("/api/change-history/{$event->id}/acknowledge")->assertConflict();
        $this->assertDatabaseCount('parser_tasks', 1);
        $this->getJson('/api/change-history/pending-count')->assertJsonPath('count', 0);
        $this->grantPermissions($user, ['workplan.view']);
        $this->getJson('/api/change-history/pending-count')->assertForbidden();
        $this->postJson("/api/change-history/{$event->id}/acknowledge")->assertForbidden();
    }

    public function test_reappearing_snapshots_and_deletion_markup_create_new_events(): void
    {
        $user = User::create(['status' => 'active']);
        $service = app(RosterChangeService::class);
        $state = ['requires_acknowledgement' => true];
        $item = ['before' => null, 'after' => ['source_external_id' => '1', 'flight_numbers_raw' => 'FV6001'], 'change_type' => 'added'];
        $a = $service->recordPending($user->id, 'rossiya_edu', '2026-09', $state, [$item]);
        $item['change_type'] = 'removed';
        $b = $service->recordPending($user->id, 'rossiya_edu', '2026-09', $state, [$item]);
        $this->assertSame('superseded', $a->fresh()->status);
        $item['change_type'] = 'added';
        $again = $service->recordPending($user->id, 'rossiya_edu', '2026-09', $state, [$item]);
        $this->assertSame($a->change_hash, $again->change_hash);
        $this->assertNotSame($a->id, $again->id);
        $this->assertSame('superseded', $b->fresh()->status);
        $this->assertSame('pending', $again->status);
    }

    public function test_expired_acknowledgement_tasks_are_retired(): void
    {
        $user = User::create(['status' => 'active']);
        $user->portalCredentials()->create(['portal' => 'rossiya_edu', 'login' => 'test', 'password_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('test'), 'status' => 'active']);
        $event = app(RosterChangeService::class)->recordPending($user->id, 'rossiya_edu', '2026-09', ['requires_acknowledgement' => true], []);
        $task = app(\App\Services\ParserTaskScheduler::class)->scheduleRosterAcknowledgement($event);
        $jobs = app(\App\Services\ParserJobService::class);
        Carbon::setTestNow('2026-10-01 00:00:01');
        $newJob = $jobs->claim(['source' => 'rossiya_edu', 'portal' => 'rossiya_edu', 'capabilities' => ['typed_tasks_v1', 'roster_acknowledgement_v1']]);
        $this->assertNotSame('acknowledge_roster_changes', $newJob['task_type'] ?? null);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('superseded', $event->fresh()->status);
    }
}
