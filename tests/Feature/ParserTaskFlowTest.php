<?php

namespace Tests\Feature;

use App\Models\InternalApiToken;
use App\Models\ParserTask;
use App\Models\PortalCredential;
use App\Models\RosterChangeEvent;
use App\Models\RosterItem;
use App\Models\SyncRun;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class ParserTaskFlowTest extends TestCase
{
    private string $token = 'parser-task-flow-token';

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'parser.roster_interval_minutes' => 60,
            'parser.max_concurrent_per_user' => 3,
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        InternalApiToken::query()->create([
            'name' => 'parser task flow test',
            'token_hash' => InternalApiToken::hashToken($this->token),
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_roster_result_creates_claimable_flight_details_task(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');
        $user = User::query()->create(['display_name' => 'Parser User']);
        PortalCredential::query()->create([
            'user_id' => $user->id,
            'portal' => 'rossiya_edu',
            'login' => 'portal-login',
            'password_encrypted' => Crypt::encryptString('portal-password'),
            'status' => 'active',
        ]);

        $rosterJob = $this->claim()->assertOk()->json('job');
        $this->assertSame('roster_refresh', $rosterJob['task_type']);
        $this->assertSame(['2026-08', '2026-09'], $rosterJob['task_payload']['months']);

        $this->withToken($this->token)
            ->postJson('/api/internal/sync-runs/'.$rosterJob['sync_run_id'].'/partial-result', [
                'sync_run_id' => $rosterJob['sync_run_id'],
                'user_id' => $user->id,
                'source' => 'rossiya_edu',
                'trigger' => 'scheduler',
                'parsed_at' => now()->toIso8601String(),
                'chunk_kind' => 'roster',
                'is_final' => false,
                'roster_source_external_id' => null,
                'roster_period' => '2026-08',
                'roster_items' => [[
                    'source_external_id' => 'flight-100',
                    'source_request_raw' => '100,2026-08-08 00:00:00.000,1',
                    'kind' => 'flight',
                    'title' => 'FV100',
                    'starts_at' => now()->addHours(12)->toIso8601String(),
                    'is_actual' => true,
                ]],
                'flight_segments' => [],
            ])
            ->assertOk();

        $this->withToken($this->token)
            ->postJson('/api/internal/sync-runs/'.$rosterJob['sync_run_id'].'/finish', [
                'status' => 'finished',
                'stats' => ['items_found' => 1, 'segments_found' => 0],
            ])
            ->assertOk();

        $detailJob = $this->claim()->assertOk()->json('job');
        $this->assertSame('flight_details', $detailJob['task_type']);
        $this->assertSame('flight-100', $detailJob['task_payload']['source_external_id']);
        $this->assertNull($detailJob['task_payload']['ends_at']);
        $this->assertSame(2, ParserTask::query()->count());
    }

    public function test_running_flight_task_completes_when_roster_item_was_cancelled(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');
        $user = User::query()->create(['display_name' => 'Cancelled Flight User']);
        $item = RosterItem::query()->create([
            'user_id' => $user->id,
            'source_external_id' => '1660764',
            'source_request_raw' => '1660764,2026-08-21 12:00:00.000,1',
            'kind' => 'flight_ring',
            'starts_at' => '2026-08-21 09:00:00',
            'is_actual' => false,
            'is_removed_from_source' => true,
        ]);
        $task = ParserTask::query()->create([
            'task_key' => 'flight_details:rossiya_edu:roster:'.$item->id,
            'user_id' => $user->id,
            'roster_item_id' => $item->id,
            'source' => 'rossiya_edu',
            'portal' => 'rossiya_edu',
            'task_type' => 'flight_details',
            'status' => 'running',
            'priority' => 20,
            'next_run_at' => now(),
            'refresh_requested' => true,
        ]);
        $syncRun = SyncRun::query()->create([
            'user_id' => $user->id,
            'parser_task_id' => $task->id,
            'roster_item_id' => $item->id,
            'source' => 'rossiya_edu',
            'task_type' => 'flight_details',
            'trigger' => 'scheduler',
            'status' => 'finished',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        app(\App\Services\ParserTaskScheduler::class)->completeTask($syncRun);

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertNull($task->next_run_at);
        $this->assertFalse($task->refresh_requested);
    }

    public function test_roster_change_notifies_and_acknowledgement_returns_current_task(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');
        config(['services.telegram_bot.token' => 'test-bot-token']);
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => random_int(100, 999)],
        ]));
        $user = User::query()->create(['display_name' => 'Parser User', 'status' => 'active']);
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_id' => 123456]);
        PortalCredential::query()->create([
            'user_id' => $user->id,
            'portal' => 'rossiya_edu',
            'login' => 'portal-login',
            'password_encrypted' => Crypt::encryptString('portal-password'),
            'status' => 'active',
        ]);

        $initialJob = $this->claim()->assertOk()->json('job');
        $initialItem = [
            'source_external_id' => 'flight-200',
            'source_request_raw' => '200,2026-08-15 06:45:00.000,1',
            'kind' => 'flight_ring',
            'flight_numbers_raw' => 'ФВ6031/ФВ6032',
            'aircraft_type_raw' => 'СУ95',
            'boards_raw' => '89100',
            'route_raw' => 'ШЕРЕМЕТ-B / ТЮМЕНЬ / ШЕРЕМЕТ-B',
            'starts_at' => '2026-08-15T06:45:00Z',
            'is_actual' => true,
            'source_payload' => ['portal_change' => ['is_changed' => false]],
        ];
        $this->sendRosterChunk($initialJob, $user, $initialItem, [
            'requires_acknowledgement' => false,
            'is_confirmed' => true,
            'confirmation_text' => 'Вы подтвердили ознакомление с текущим планом',
        ]);
        $this->finish($initialJob['sync_run_id']);

        ParserTask::query()->where('task_type', 'roster_refresh')->update(['next_run_at' => now()]);
        $changedJob = $this->claim()->assertOk()->json('job');
        $changedItem = array_merge($initialItem, [
            'source_request_raw' => '200,2026-08-15 07:45:00.000,1',
            'flight_numbers_raw' => 'ФВ6033/ФВ6034',
            'route_raw' => 'ШЕРЕМЕТ-B / ОРСК / ШЕРЕМЕТ-B',
            'starts_at' => '2026-08-15T07:45:00Z',
            'source_payload' => ['portal_change' => [
                'is_changed' => true,
                'is_warning' => true,
                'changed_fields' => ['starts_at', 'flight_numbers_raw', 'route_raw'],
            ]],
        ]);
        $this->sendRosterChunk($changedJob, $user, $changedItem, [
            'requires_acknowledgement' => true,
            'is_confirmed' => false,
            'confirmation_text' => 'Последнее подтверждение было 07.08.2026 09:26',
        ]);
        $this->finish($changedJob['sync_run_id']);

        $event = RosterChangeEvent::query()->sole();
        $this->assertSame('pending', $event->status);
        $this->assertSame('changed', $event->changes[0]['change_type']);
        $this->assertSame('ФВ6031/ФВ6032', $event->changes[0]['before']['flight_numbers_raw']);
        $this->assertSame('ФВ6033/ФВ6034', $event->changes[0]['after']['flight_numbers_raw']);
        $this->assertNotNull($event->notified_at);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), '<b>Изменена задача:</b>'));

        app(TelegramBotService::class)->handle(['callback_query' => [
            'id' => 'callback-1',
            'from' => ['id' => 123456],
            'message' => ['chat' => ['id' => 123456]],
            'data' => 'roster.ack:'.$event->id,
        ]]);

        $ackJob = $this->claim()->assertOk()->json('job');
        $this->assertSame('acknowledge_roster_changes', $ackJob['task_type']);
        $this->assertSame($event->id, $ackJob['task_payload']['roster_change_event_id']);

        $this->withToken($this->token)
            ->postJson('/api/internal/sync-runs/'.$ackJob['sync_run_id'].'/partial-result', [
                'sync_run_id' => $ackJob['sync_run_id'],
                'user_id' => $user->id,
                'source' => 'rossiya_edu',
                'trigger' => 'scheduler',
                'parsed_at' => now()->toIso8601String(),
                'chunk_kind' => 'roster_acknowledgement',
                'is_final' => false,
                'roster_source_external_id' => null,
                'roster_period' => '2026-08',
                'roster_change_event_id' => $event->id,
                'roster_change_hash' => $event->change_hash,
                'roster_change_state' => [
                    'requires_acknowledgement' => false,
                    'is_confirmed' => true,
                    'confirmation_text' => 'Вы подтвердили ознакомление с текущим планом 07.08.2026 14:52',
                ],
                'roster_items' => [$changedItem],
                'flight_segments' => [],
            ])
            ->assertOk();
        $this->finish($ackJob['sync_run_id']);

        $event->refresh();
        $this->assertSame('acknowledged', $event->status);
        $this->assertNotNull($event->acknowledgement_notified_at);
        Http::assertSent(fn ($request) => isset($request['text'])
            && str_contains((string) $request['text'], 'Актуальная задача'));
    }

    public function test_admin_approves_pending_user_with_inline_button(): void
    {
        config(['services.telegram_bot.token' => 'test-bot-token']);
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 501],
        ]));

        $admin = User::query()->create(['display_name' => 'Admin', 'status' => 'active']);
        TelegramAccount::query()->create([
            'user_id' => $admin->id,
            'telegram_id' => 100001,
            'is_admin' => true,
        ]);
        $pendingUser = User::query()->create(['display_name' => 'Ромарио', 'status' => 'pending']);
        $pendingAccount = TelegramAccount::query()->create([
            'user_id' => $pendingUser->id,
            'telegram_id' => 511700424,
        ]);
        PortalCredential::query()->create([
            'user_id' => $pendingUser->id,
            'portal' => 'rossiya_edu',
            'login' => '124312',
            'password_encrypted' => Crypt::encryptString('portal-password'),
            'status' => 'active',
        ]);

        app(TelegramBotService::class)->handle(['message' => [
            'chat' => ['id' => 100001],
            'from' => ['id' => 100001],
            'text' => '/pending',
        ]]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === 100001
            && str_contains((string) $request['text'], 'Табельный номер: <code>124312</code>')
            && ! str_contains((string) $request['text'], 'Telegram ID')
            && ($request['reply_markup']['inline_keyboard'][0][0] ?? null) === [
                'text' => 'Одобрить',
                'callback_data' => 'admin.approve:'.$pendingAccount->id,
            ]);

        app(TelegramBotService::class)->handle(['callback_query' => [
            'id' => 'approve-callback',
            'from' => ['id' => 100001],
            'message' => [
                'message_id' => 501,
                'chat' => ['id' => 100001],
            ],
            'data' => 'admin.approve:'.$pendingAccount->id,
        ]]);

        $this->assertSame('active', $pendingUser->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/editMessageReplyMarkup')
            && $request['chat_id'] === 100001
            && $request['message_id'] === 501);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === 511700424
            && str_contains((string) $request['text'], 'Доступ одобрен'));
    }

    public function test_expired_worker_cannot_renew_a_task_reassigned_to_another_vm(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $user = User::query()->create(['display_name' => 'Heartbeat User']);
        PortalCredential::query()->create([
            'user_id' => $user->id, 'portal' => 'rossiya_edu', 'login' => 'test',
            'password_encrypted' => Crypt::encryptString('test'), 'status' => 'active',
        ]);
        $oldJob = $this->claim()->assertOk()->json('job');
        $oldRun = SyncRun::findOrFail($oldJob['sync_run_id']);
        $oldWorker = 'vm-1:worker-'.$oldRun->id;
        $oldUrl = '/api/internal/parser-jobs/'.$oldRun->id.'/heartbeat';
        Carbon::setTestNow('2026-09-09 12:01:00');
        $this->withToken($this->token)->postJson($oldUrl, ['locked_by' => $oldWorker, 'lock_seconds' => 60])->assertOk();
        $this->assertSame($oldWorker, $oldRun->fresh()->worker_id);
        $this->assertSame($oldWorker, ParserTask::findOrFail($oldJob['task_id'])->locked_by);

        // An expired lease must not be revived, even before the next VM claims it.
        Carbon::setTestNow('2026-09-09 12:02:00');
        $oldState = $oldRun->fresh()->getAttributes();
        $this->postJson($oldUrl, ['locked_by' => $oldWorker])->assertStatus(409);
        $this->assertSame($oldState, $oldRun->fresh()->getAttributes());

        $newJob = $this->withToken($this->token)->postJson('/api/internal/parser-jobs/claim', [
            'source' => 'rossiya_edu', 'portal' => 'rossiya_edu',
            'locked_by' => 'vm-2:supervisor', 'lock_seconds' => 900,
            'capabilities' => ['typed_tasks_v1', 'roster_acknowledgement_v1'],
        ])->assertOk()->json('job');
        $this->assertSame($oldJob['task_id'], $newJob['task_id']);
        $this->assertNotSame($oldRun->id, $newJob['sync_run_id']);
        $this->assertSame($oldJob['attempt'] + 1, $newJob['attempt']);
        $this->assertSame('failed', $oldRun->fresh()->status);

        $newRun = SyncRun::findOrFail($newJob['sync_run_id']);
        $newUrl = '/api/internal/parser-jobs/'.$newRun->id.'/heartbeat';
        $newWorker = 'vm-2:worker-'.$newRun->id;
        $this->postJson($newUrl, ['locked_by' => $newWorker])->assertOk();
        $task = ParserTask::findOrFail($newJob['task_id']);
        $taskState = $task->getAttributes();
        $runState = $newRun->fresh()->getAttributes();
        $oldState = $oldRun->fresh()->getAttributes();

        $this->postJson($oldUrl, ['locked_by' => $oldWorker, 'lock_seconds' => 7200])->assertStatus(409);
        $this->postJson($newUrl, ['locked_by' => $oldWorker, 'lock_seconds' => 7200])->assertStatus(409);
        $this->assertSame($taskState, $task->fresh()->getAttributes());
        $this->assertSame($runState, $newRun->fresh()->getAttributes());
        $this->assertSame($oldState, $oldRun->fresh()->getAttributes());

        Carbon::setTestNow('2026-09-09 12:03:00');
        $this->postJson($newUrl, ['locked_by' => $newWorker])->assertOk();
        $this->assertTrue($newRun->fresh()->lock_expires_at->equalTo(now()->addSeconds(900)));
        $this->finish($newRun->id);
        $taskState = $task->fresh()->getAttributes();
        $runState = $newRun->fresh()->getAttributes();
        $this->postJson($newUrl, ['locked_by' => $newWorker])->assertStatus(409);
        $this->assertSame($taskState, $task->fresh()->getAttributes());
        $this->assertSame($runState, $newRun->fresh()->getAttributes());
    }

    public function test_heartbeat_checks_task_attempt_owner_status_and_expiry_before_any_write(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $user = User::query()->create(['display_name' => 'Heartbeat User']);
        PortalCredential::query()->create([
            'user_id' => $user->id, 'portal' => 'rossiya_edu', 'login' => 'test',
            'password_encrypted' => Crypt::encryptString('test'), 'status' => 'active',
        ]);
        $job = $this->claim()->assertOk()->json('job');
        $run = SyncRun::findOrFail($job['sync_run_id']);
        $task = ParserTask::findOrFail($job['task_id']);
        $original = $task->getAttributes();
        $runState = $run->getAttributes();
        foreach ([
            ['attempts' => $task->attempts + 1],
            ['locked_by' => 'another-vm'],
            ['status' => 'scheduled'],
            ['lock_expires_at' => now()],
            ['lock_expires_at' => null],
        ] as $change) {
            $task->setRawAttributes($original)->forceFill($change)->save();
            $taskState = $task->fresh()->getAttributes();
            $this->withToken($this->token)->postJson('/api/internal/parser-jobs/'.$run->id.'/heartbeat', [
                'locked_by' => 'vm-1:worker-'.$run->id,
            ])->assertStatus(409);
            $this->assertSame($runState, $run->fresh()->getAttributes());
            $this->assertSame($taskState, $task->fresh()->getAttributes());
        }
    }

    private function sendRosterChunk(array $job, User $user, array $item, array $changeState): void
    {
        $this->withToken($this->token)
            ->postJson('/api/internal/sync-runs/'.$job['sync_run_id'].'/partial-result', [
                'sync_run_id' => $job['sync_run_id'],
                'user_id' => $user->id,
                'source' => 'rossiya_edu',
                'trigger' => 'scheduler',
                'parsed_at' => now()->toIso8601String(),
                'chunk_kind' => 'roster',
                'is_final' => false,
                'roster_source_external_id' => null,
                'roster_period' => '2026-08',
                'roster_change_state' => $changeState,
                'roster_items' => [$item],
                'flight_segments' => [],
            ])
            ->assertOk();
    }

    private function finish(int $syncRunId): void
    {
        $this->withToken($this->token)
            ->postJson('/api/internal/sync-runs/'.$syncRunId.'/finish', [
                'status' => 'finished',
                'stats' => ['items_found' => 1, 'segments_found' => 0],
            ])
            ->assertOk();
    }

    private function claim()
    {
        return $this->withToken($this->token)->postJson('/api/internal/parser-jobs/claim', [
            'source' => 'rossiya_edu',
            'portal' => 'rossiya_edu',
            'locked_by' => 'test-supervisor',
            'lock_seconds' => 900,
            'capabilities' => ['typed_tasks_v1', 'roster_acknowledgement_v1'],
        ]);
    }
}
