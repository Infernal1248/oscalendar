<?php

namespace Tests\Feature;

use App\Models\{FlightTimeEntry, InternalApiToken, ParserTask, PortalCredential, PortalProfile, SyncRun, User};
use Illuminate\Support\Facades\{Artisan, Crypt, DB, Http};
use Tests\TestCase;

class FlightTimeTest extends TestCase
{
    private User $admin;
    private User $tester;
    private User $ordinary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-30 12:00:00', 'UTC'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        $this->admin = User::create(['status' => 'active', 'role' => 'admin']);
        $this->tester = User::create(['status' => 'active']);
        $this->ordinary = User::create(['status' => 'active']);
        foreach ([$this->admin, $this->tester, $this->ordinary] as $user) {
            PortalCredential::create(['user_id' => $user->id, 'portal' => 'rossiya_edu', 'login' => 'user-'.$user->id,
                'password_encrypted' => Crypt::encryptString('test'), 'status' => 'active']);
            PortalProfile::create(['user_id' => $user->id, 'source' => 'rossiya_edu', 'full_name' => 'Тестовый Пользователь',
                'personnel_number' => '123456', 'synced_at' => now()]);
        }
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('internal-test'), 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function row(string $date = '2026-10-30', int $position = 1, int $minutes = 153): array
    {
        return array_merge(array_fill_keys(FlightTimeEntry::MINUTES, null), [
            'entry_date' => $date, 'row_position' => $position, 'flight_number' => 'ФВ6477',
            'description' => 'ШЕРЕМЕТ - МУРМАНСК', 'flight_assignment_number' => '1022/013655',
            'actual_flight_minutes' => $minutes, 'source_payload' => array_fill(0, 13, ''),
        ]);
    }

    private function newRun(?User $user = null, string $from = '2026-10-24', string $to = '2026-10-30'): SyncRun
    {
        return SyncRun::create(['user_id' => ($user ?? $this->tester)->id, 'source' => 'rossiya_edu', 'task_type' => 'flight_time',
            'task_payload' => ['date_from' => $from, 'date_to' => $to], 'status' => 'running',
            'started_at' => now()->subSecond(), 'lock_expires_at' => now()->addMinutes(15)]);
    }

    private function snapshot(array $rows = [], string $from = '2026-10-24', string $to = '2026-10-30'): array
    {
        $totals = [];
        foreach (FlightTimeEntry::MINUTES as $field) $totals[$field] = array_sum(array_column($rows, $field));
        return ['date_from' => $from, 'date_to' => $to, 'nalet_user_id' => '9054',
            'portal_identity' => ['full_name' => 'Тестовый Пользователь', 'personnel_number' => '123456'],
            'entries' => $rows, 'totals' => $totals];
    }

    private function upload(SyncRun $run, array $payload)
    {
        return $this->withToken('internal-test')->postJson('/api/internal/sync-runs/'.$run->id.'/flight-time', $payload);
    }

    private function seedRows(User $user, array $rows): void
    {
        foreach ($rows as $row) FlightTimeEntry::create($row + ['user_id' => $user->id]);
    }

    private function claim(User $user, bool $capable = true): ?array
    {
        return $this->withToken('internal-test')->postJson('/api/internal/parser-jobs/claim', [
            'source' => 'rossiya_edu', 'portal' => 'rossiya_edu', 'user_id' => $user->id, 'locked_by' => 'test-worker',
            'capabilities' => $capable ? ['typed_tasks_v1', 'flight_time_v1'] : ['typed_tasks_v1'],
        ])->assertOk()->json('job');
    }

    public function test_collection_tasks_are_seeded_for_every_active_connection_once_and_history_cutoff_is_frozen(): void
    {
        $blocked = User::create(['status' => 'blocked']);
        PortalCredential::create(['user_id' => $blocked->id, 'portal' => 'rossiya_edu', 'login' => 'blocked',
            'password_encrypted' => Crypt::encryptString('test'), 'status' => 'active']);
        Artisan::call('flight-time:schedule');
        $this->assertSame(249, ParserTask::where('task_type', 'flight_time')->count());
        foreach ([$this->admin, $this->tester, $this->ordinary] as $user) {
            $this->assertSame(83, ParserTask::where('user_id', $user->id)->count());
            $history = ParserTask::where('task_key', "flight_time:rossiya_edu:user:{$user->id}:2026-10")->sole();
            $this->assertSame(['date_from' => '2026-10-01', 'date_to' => '2026-10-23', 'history' => true], $history->payload);
        }
        $this->assertDatabaseMissing('parser_tasks', ['user_id' => $blocked->id]);
        $this->travel(40)->days();
        Artisan::call('flight-time:schedule');
        $this->assertSame(249, ParserTask::where('task_type', 'flight_time')->count());
        $this->assertSame('2026-10-23', ParserTask::where('task_key', "flight_time:rossiya_edu:user:{$this->tester->id}:2026-10")->sole()->payload['date_to']);
    }

    public function test_page_and_api_are_restricted_to_admin_and_user_two_and_always_scope_to_the_viewer(): void
    {
        $this->assertSame(2, $this->tester->id);
        $this->seedRows($this->tester, [$this->row()]);
        $this->seedRows($this->ordinary, [$this->row('2026-10-29')]);
        $this->getJson('/api/flight-time')->assertUnauthorized();
        $this->actingAs($this->ordinary, 'sanctum')->getJson('/api/flight-time')->assertForbidden();
        $this->getJson('/api/flight-time/metadata')->assertForbidden();
        $this->assertNotContains('flight-time', $this->getJson('/api/account')->assertOk()->json('navigation'));
        $this->actingAs($this->tester, 'sanctum')->getJson('/api/flight-time?user_id='.$this->ordinary->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.user_id', $this->tester->id);
        $this->assertContains('flight-time', $this->getJson('/api/account')->assertOk()->json('navigation'));
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/flight-time/metadata')->assertOk()->assertJsonCount(13, 'columns');
        $this->getJson('/api/flight-time')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_weekly_replacement_is_atomic_idempotent_and_preserves_older_history_and_other_users(): void
    {
        $this->seedRows($this->tester, [$this->row('2020-01-01'), $this->row('2026-10-23'), $this->row()]);
        $this->seedRows($this->ordinary, [$this->row()]);
        $run = $this->newRun();
        $payload = $this->snapshot([$this->row('2026-10-29', 1, 155)]);
        $this->upload($run, $payload)->assertOk();
        $this->upload($run, $payload)->assertOk();
        $this->assertDatabaseCount('flight_time_entries', 4);
        $this->assertDatabaseHas('flight_time_entries', ['user_id' => $this->tester->id, 'entry_date' => '2020-01-01']);
        $this->assertDatabaseHas('flight_time_entries', ['user_id' => $this->tester->id, 'entry_date' => '2026-10-23']);
        $this->assertDatabaseMissing('flight_time_entries', ['user_id' => $this->tester->id, 'entry_date' => '2026-10-30']);
        $this->assertDatabaseHas('flight_time_entries', ['user_id' => $this->ordinary->id, 'entry_date' => '2026-10-30']);
        $this->assertSame('1022/013655', FlightTimeEntry::where('entry_date', '2026-10-29')->sole()->flight_assignment_number);
        $this->assertSame('9054', PortalProfile::find($this->tester->id)->nalet_user_id);
        $payload['entries'][0]['actual_flight_minutes']++;
        $this->upload($run, $payload)->assertConflict();
    }

    public function test_empty_validated_snapshot_clears_only_requested_days(): void
    {
        $this->seedRows($this->tester, [$this->row('2020-01-01'), $this->row()]);
        $this->upload($this->newRun(), $this->snapshot())->assertOk();
        $this->assertDatabaseCount('flight_time_entries', 1);
    }

    public function test_invalid_totals_identity_rows_and_period_never_delete_previous_data(): void
    {
        $this->seedRows($this->tester, [$this->row()]);
        $run = $this->newRun();
        $bad = $this->snapshot([$this->row()]); $bad['totals']['actual_flight_minutes']++;
        $this->upload($run, $bad)->assertUnprocessable();
        $bad = $this->snapshot([$this->row()]); $bad['portal_identity']['personnel_number'] = '987654';
        $this->upload($run, $bad)->assertConflict();
        $this->upload($run, $this->snapshot([$this->row('2026-10-23')]))->assertUnprocessable();
        $this->upload($run, $this->snapshot([$this->row('2026-10-30', 2)]))->assertUnprocessable();
        $this->upload($run, $this->snapshot([$this->row()], '2026-10-25'))->assertConflict();
        $this->assertDatabaseCount('flight_time_entries', 1);
        $this->assertNull($run->fresh()->stats);
    }

    public function test_stale_lease_wrong_task_and_finish_without_upload_are_rejected(): void
    {
        $run = $this->newRun();
        $this->withToken('internal-test')->postJson('/api/internal/sync-runs/'.$run->id.'/finish', ['status' => 'finished'])->assertConflict();
        $run->update(['lock_expires_at' => now()->subSecond()]);
        $this->upload($run, $this->snapshot())->assertConflict();
        $run->update(['lock_expires_at' => now()->addHour(), 'task_type' => 'roster_refresh']);
        $this->upload($run, $this->snapshot())->assertConflict();
    }

    public function test_ordinary_user_collection_is_allowed_even_though_their_view_is_forbidden(): void
    {
        $this->upload($this->newRun($this->ordinary), $this->snapshot([$this->row()]))->assertOk();
        $this->assertDatabaseHas('flight_time_entries', ['user_id' => $this->ordinary->id]);
    }

    public function test_workers_require_capability_daily_dates_roll_and_history_completes_once(): void
    {
        Artisan::call('flight-time:schedule');
        foreach ([$this->tester] as $user) ParserTask::create(['task_key' => "roster_refresh:rossiya_edu:user:{$user->id}",
            'user_id' => $user->id, 'source' => 'rossiya_edu', 'portal' => 'rossiya_edu', 'task_type' => 'roster_refresh', 'status' => 'paused']);
        $this->assertNull($this->claim($this->tester, false));
        $job = $this->claim($this->tester);
        $this->assertSame('2026-10-24', $job['task_payload']['date_from']);
        $this->assertSame('2026-10-30', $job['task_payload']['date_to']);
        $this->assertNull($this->claim($this->tester)); // One nalet import per employee at a time.
        $this->upload(SyncRun::find($job['sync_run_id']), $this->snapshot())->assertOk();
        $this->withToken('internal-test')->postJson('/api/internal/sync-runs/'.$job['sync_run_id'].'/finish', ['status' => 'finished'])->assertOk();
        $task = ParserTask::find($job['task_id']);
        $this->assertSame('scheduled', $task->status);
        $this->assertTrue($task->next_run_at->equalTo(now()->addDay()));
        $history = $this->claim($this->tester);
        $this->assertTrue($history['task_payload']['history']);
        $run = SyncRun::find($history['sync_run_id']);
        $this->upload($run, $this->snapshot([], $history['task_payload']['date_from'], $history['task_payload']['date_to']))->assertOk();
        $this->withToken('internal-test')->postJson('/api/internal/sync-runs/'.$run->id.'/finish', ['status' => 'finished'])->assertOk();
        $this->assertSame('completed', ParserTask::find($history['task_id'])->status);
        ParserTask::where('user_id', $this->tester->id)->where('id', '!=', $task->id)->update(['status' => 'completed']);
        $this->travel(1)->days();
        $next = $this->claim($this->tester);
        $this->assertSame('2026-10-25', $next['task_payload']['date_from']);
        $this->assertSame('2026-10-31', $next['task_payload']['date_to']);
    }

    public function test_duration_filters_sorting_and_totals_cover_all_filtered_rows_not_just_one_page(): void
    {
        $this->seedRows($this->tester, [$this->row('2026-10-29', 1, 30), $this->row('2026-10-30', 1, 153)]);
        $this->actingAs($this->tester, 'sanctum')->getJson('/api/flight-time?per_page=1&sort_by=actual_flight_minutes&sort_order=asc&group_by=flight_assignment_number')
            ->assertOk()->assertJsonPath('data.0.actual_flight_minutes', 30)->assertJsonPath('totals.actual_flight_minutes', 183)
            ->assertJsonPath('group_totals.0.totals.actual_flight_minutes', 183)->assertJsonPath('total', 2);
        $query = http_build_query(['filter_rules' => ['actual_flight_minutes' => ['operator' => 'and',
            'constraints' => [['matchMode' => 'gte', 'value' => '2:00']]]]]);
        $this->getJson('/api/flight-time?'.$query)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('totals.actual_flight_minutes', 153);
        $this->getJson('/api/flight-time?group_by=entry_month')->assertOk()->assertJsonPath('group_totals.0.value', '2026-10');
        $this->getJson('/api/flight-time?sort_by=password_encrypted')->assertUnprocessable();
    }
}
