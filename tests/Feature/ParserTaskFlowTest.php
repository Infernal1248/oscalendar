<?php

namespace Tests\Feature;

use App\Models\InternalApiToken;
use App\Models\ParserTask;
use App\Models\PortalCredential;
use App\Models\RosterChangeEvent;
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
        $this->assertSame('ФВ6031/ФВ6032', $event->changes[0]['before']['flight_numbers_raw']);
        $this->assertSame('ФВ6033/ФВ6034', $event->changes[0]['after']['flight_numbers_raw']);
        $this->assertNotNull($event->notified_at);

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
