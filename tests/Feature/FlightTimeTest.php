<?php

namespace Tests\Feature;

use App\Models\{FlightTimeEntry, FlightTimeOpeningBalance, InternalApiToken, ParserTask, PortalCredential, PortalProfile, SyncRun, User};
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

    public function test_manual_backfill_creates_24_months_only_for_user_nine_and_does_not_requeue_existing_tasks(): void
    {
        $user = User::forceCreate(['id' => 9, 'status' => 'active']);
        PortalCredential::create(['user_id' => 9, 'portal' => 'rossiya_edu', 'login' => 'user-9',
            'password_encrypted' => Crypt::encryptString('test'), 'status' => 'active']);
        $this->seedRows($user, [$this->row('2020-01-01')]);
        $arguments = ['user_id' => 9, 'from' => '2018-01', 'to' => '2019-12'];
        $this->assertSame(0, Artisan::call('flight-time:backfill', $arguments));
        $this->assertSame(24, ParserTask::count());
        $this->assertSame(24, ParserTask::where('user_id', 9)->where('status', 'scheduled')->count());
        $first = ParserTask::where('task_key', 'flight_time:rossiya_edu:user:9:2018-01')->sole();
        $last = ParserTask::where('task_key', 'flight_time:rossiya_edu:user:9:2019-12')->sole();
        $this->assertSame(['date_from' => '2018-01-01', 'date_to' => '2018-01-31', 'history' => true], $first->payload);
        $this->assertSame(['date_from' => '2019-12-01', 'date_to' => '2019-12-31', 'history' => true], $last->payload);
        $first->update(['status' => 'completed', 'next_run_at' => null]);
        $this->assertSame(0, Artisan::call('flight-time:backfill', $arguments));
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame(24, ParserTask::count());
        $this->assertSame(1, FlightTimeEntry::count());
        ParserTask::create(['task_key' => 'roster_refresh:rossiya_edu:user:9', 'user_id' => 9,
            'source' => 'rossiya_edu', 'portal' => 'rossiya_edu', 'task_type' => 'roster_refresh', 'status' => 'paused']);
        $job = $this->claim($user);
        $this->assertSame('2018-02-01', $job['task_payload']['date_from']);
        $this->assertTrue($job['task_payload']['history']);
        $run = SyncRun::findOrFail($job['sync_run_id']);
        $this->upload($run, $this->snapshot([$this->row('2018-02-05')], '2018-02-01', '2018-02-28'))->assertOk();
        $this->withToken('internal-test')->postJson('/api/internal/sync-runs/'.$run->id.'/finish', ['status' => 'finished'])->assertOk();
        $this->assertSame('completed', ParserTask::find($job['task_id'])->status);
        $this->assertDatabaseHas('flight_time_entries', ['user_id' => 9, 'entry_date' => '2018-02-05']);
        Artisan::call('flight-time:schedule');
        $this->assertSame(107, ParserTask::where('user_id', 9)->where('task_type', 'flight_time')->count());
        $this->assertSame(83, ParserTask::where('user_id', $this->tester->id)->where('task_type', 'flight_time')->count());
    }

    public function test_manual_backfill_rejects_invalid_dates_and_inactive_or_missing_connections(): void
    {
        foreach ([['from' => '2017-12'], ['from' => '2019-13'], ['from' => '2019-12', 'to' => '2019-01'],
            ['to' => '2026-10'], ['user_id' => 999], ['user_id' => 'all']] as $override) {
            $this->assertSame(1, Artisan::call('flight-time:backfill', array_replace([
                'user_id' => $this->ordinary->id, 'from' => '2018-01', 'to' => '2019-12',
            ], $override)));
        }
        PortalCredential::where('user_id', $this->ordinary->id)->update(['status' => 'disabled']);
        $this->assertSame(1, Artisan::call('flight-time:backfill', ['user_id' => $this->ordinary->id, 'from' => '2018-01', 'to' => '2019-12']));
        $this->assertSame(0, ParserTask::count());
    }

    public function test_opening_balances_are_private_replaceable_and_added_only_to_the_global_summary(): void
    {
        $this->seedRows($this->tester, [array_replace($this->row(), ['actual_night_minutes' => 30, 'actual_work_minutes' => 300])]);
        $this->seedRows($this->ordinary, [$this->row('2020-01-01', 1, 999)]);
        $payload = ['flight_time' => '959:46', 'airborne_time' => '787:26', 'night_time' => '334:36', 'covered_until' => '2020-11-30'];
        $this->patchJson('/api/flight-time/opening-balance', $payload)->assertUnauthorized();
        $this->actingAs($this->ordinary, 'sanctum')->patchJson('/api/flight-time/opening-balance', $payload)->assertForbidden();
        $this->actingAs($this->tester, 'sanctum')->patchJson('/api/flight-time/opening-balance', $payload)->assertOk()
            ->assertJsonPath('opening_balance.flight_minutes', 57586)->assertJsonPath('opening_balance.airborne_minutes', 47246)
            ->assertJsonPath('opening_balance.night_minutes', 20076)->assertJsonPath('opening_balance.covered_until', '2020-11-30')
            ->assertJsonPath('all_time_totals.actual_flight_minutes', 57739)->assertJsonPath('all_time_totals.actual_night_minutes', 20106)
            ->assertJsonPath('portal_totals.actual_flight_minutes', 153)->assertJsonPath('all_time_totals.flights', 1);
        $this->patchJson('/api/flight-time/opening-balance', $payload)->assertOk()->assertJsonPath('all_time_totals.actual_flight_minutes', 57739);
        $this->assertSame(1, FlightTimeOpeningBalance::count());
        $this->getJson('/api/flight-time?date_from=2027-01-01')->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('all_time_totals.actual_flight_minutes', 57739)->assertJsonPath('opening_balance.airborne_minutes', 47246);
        $this->getJson('/api/flight-time?per_page=1&group_by=entry_month')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('totals.actual_flight_minutes', 153)->assertJsonPath('group_totals.0.totals.actual_flight_minutes', 153)
            ->assertJsonPath('all_time_totals.actual_work_minutes', 300);
        $this->upload($this->newRun(), $this->snapshot([$this->row('2026-10-30', 1, 160)]))->assertOk();
        $this->actingAs($this->tester, 'sanctum')->getJson('/api/flight-time')->assertOk()
            ->assertJsonPath('all_time_totals.actual_flight_minutes', 57746)->assertJsonPath('opening_balance.flight_minutes', 57586);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/flight-time')->assertOk()
            ->assertJsonPath('all_time_totals.actual_flight_minutes', 0)->assertJsonPath('opening_balance.airborne_minutes', null);
    }

    public function test_opening_flight_count_is_added_once_preserved_by_older_clients_and_clearable(): void
    {
        $this->seedRows($this->tester, [$this->row()]);
        $this->actingAs($this->tester, 'sanctum');
        $payload = ['flight_time' => null, 'airborne_time' => null, 'night_time' => null, 'covered_until' => null, 'flights' => 482];
        $this->patchJson('/api/flight-time/opening-balance', $payload)->assertOk()
            ->assertJsonPath('opening_balance.flights', 482)->assertJsonPath('portal_totals.flights', 1)
            ->assertJsonPath('all_time_totals.flights', 483);
        $this->patchJson('/api/flight-time/opening-balance', $payload)->assertOk()->assertJsonPath('all_time_totals.flights', 483);
        unset($payload['flights']);
        $this->patchJson('/api/flight-time/opening-balance', $payload)->assertOk()->assertJsonPath('opening_balance.flights', 482);
        $this->getJson('/api/flight-time?date_from=2027-01-01')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('all_time_totals.flights', 483);
        foreach ([-1, 1.5, 'invalid'] as $value) {
            $this->patchJson('/api/flight-time/opening-balance', $payload + ['flights' => $value])->assertUnprocessable();
        }
        $this->assertSame(482, FlightTimeOpeningBalance::sole()->flights);
        $this->patchJson('/api/flight-time/opening-balance', $payload + ['flights' => 0])->assertOk()
            ->assertJsonPath('opening_balance.flights', 0)->assertJsonPath('all_time_totals.flights', 1);
        $this->patchJson('/api/flight-time/opening-balance', $payload + ['flights' => null])->assertOk()
            ->assertJsonPath('opening_balance.flights', null)->assertJsonPath('all_time_totals.flights', 1);
        $this->assertSame(1, FlightTimeEntry::count());
    }

    public function test_opening_balance_validation_and_clearing_preserve_portal_records(): void
    {
        $this->seedRows($this->tester, [$this->row()]);
        $this->actingAs($this->tester, 'sanctum');
        $payload = ['flight_time' => '1:00', 'airborne_time' => '0:45', 'night_time' => '0:00', 'covered_until' => null];
        foreach ([['flight_time' => '-1:00'], ['night_time' => '0:60'], ['airborne_time' => '1.5'],
            ['covered_until' => '2030-01-01'], ['user_id' => $this->ordinary->id]] as $override) {
            $this->patchJson('/api/flight-time/opening-balance', array_replace($payload, $override))->assertUnprocessable();
        }
        $this->assertSame(0, FlightTimeOpeningBalance::count());
        $this->patchJson('/api/flight-time/opening-balance', $payload)->assertOk()
            ->assertJsonPath('opening_balance.night_minutes', 0)->assertJsonPath('all_time_totals.actual_flight_minutes', 213);
        $this->patchJson('/api/flight-time/opening-balance', array_fill_keys(array_keys($payload), null))->assertOk()
            ->assertJsonPath('opening_balance.airborne_minutes', null)->assertJsonPath('all_time_totals.actual_flight_minutes', 153);
        $this->assertSame(1, FlightTimeEntry::count());
        $this->assertSame(1, FlightTimeOpeningBalance::count());
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

    public function test_signed_work_durations_are_saved_summed_and_filtered_without_losing_original_cells(): void
    {
        $first = array_replace($this->row('2024-06-18', 1, 10), ['actual_work_minutes' => 510, 'forecast_air_work_minutes' => 510]);
        $second = array_replace($this->row('2024-06-18', 2, 115), ['actual_work_minutes' => -10, 'forecast_air_work_minutes' => -10]);
        $second['source_payload'][5] = '-01,50';
        $run = $this->newRun(null, '2024-06-18', '2024-06-18');
        $this->upload($run, $this->snapshot([$first, $second], '2024-06-18', '2024-06-18'))->assertOk();
        $stored = FlightTimeEntry::where('row_position', 2)->sole();
        $this->assertSame(-10, $stored->actual_work_minutes);
        $this->assertSame('-01,50', $stored->source_payload[5]);
        $this->assertSame(500, $run->fresh()->stats['flight_time_totals']['actual_work_minutes']);

        $this->actingAs($this->tester, 'sanctum')->getJson('/api/flight-time?group_by=entry_month&sort_by=actual_work_minutes&sort_order=asc')
            ->assertOk()->assertJsonPath('data.0.actual_work_minutes', -10)
            ->assertJsonPath('totals.actual_work_minutes', 500)->assertJsonPath('all_time_totals.actual_work_minutes', 500);
        foreach (['-0:10', '−0:10', '-10'] as $value) {
            $query = http_build_query(['group_by' => 'entry_month', 'filter_rules' => ['actual_work_minutes' => ['operator' => 'and',
                'constraints' => [['matchMode' => 'equals', 'value' => $value]]]]]);
            $this->getJson('/api/flight-time?'.$query)->assertOk()->assertJsonPath('total', 1)
                ->assertJsonPath('totals.actual_work_minutes', -10)->assertJsonPath('group_totals.0.totals.actual_work_minutes', -10)
                ->assertJsonPath('all_time_totals.actual_work_minutes', 500);
        }
    }

    public function test_negative_totals_are_accepted_but_wrong_totals_preserve_previous_rows(): void
    {
        $this->seedRows($this->tester, [$this->row()]);
        $negative = array_replace($this->row(), ['actual_work_minutes' => -10, 'forecast_air_work_minutes' => -10]);
        $run = $this->newRun();
        $snapshot = $this->snapshot([$negative]);
        $wrong = $snapshot; $wrong['totals']['actual_work_minutes'] = -11;
        $this->upload($run, $wrong)->assertUnprocessable();
        $this->assertNull(FlightTimeEntry::sole()->actual_work_minutes);
        $this->upload($run, $snapshot)->assertOk();
        $this->assertSame(-10, FlightTimeEntry::sole()->actual_work_minutes);
        $this->assertSame(-10, $run->fresh()->stats['flight_time_totals']['actual_work_minutes']);
        $this->actingAs($this->tester, 'sanctum')->getJson('/api/flight-time')->assertOk()
            ->assertJsonPath('all_time_totals.actual_work_minutes', -10)->assertJsonPath('totals.actual_work_minutes', -10);
    }

    public function test_all_time_summary_ignores_period_filters_and_pagination_and_excludes_other_users(): void
    {
        $this->seedRows($this->tester, [
            array_replace($this->row('2020-01-01', 1, 60), ['actual_night_minutes' => 15, 'actual_work_minutes' => 100]),
            array_replace($this->row('2026-10-30', 1, 153), ['actual_night_minutes' => 30, 'actual_work_minutes' => 200]),
            array_replace($this->row('2026-10-30', 2), ['flight_number' => null, 'actual_flight_minutes' => null, 'actual_work_minutes' => 50]),
            array_replace($this->row('2026-10-30', 3), ['actual_flight_minutes' => null, 'planned_flight_minutes' => 40]),
        ]);
        $this->seedRows($this->ordinary, [$this->row('2020-01-01', 1, 999)]);
        $query = http_build_query(['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'per_page' => 1,
            'group_by' => 'entry_month', 'sort_by' => 'actual_flight_minutes',
            'filter_rules' => ['actual_flight_minutes' => ['operator' => 'and', 'constraints' => [['matchMode' => 'gte', 'value' => '2:00']]]]]);
        $this->actingAs($this->tester, 'sanctum')->getJson('/api/flight-time?'.$query)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('total', 1)->assertJsonPath('totals.actual_flight_minutes', 153)
            ->assertJsonPath('all_time_totals.actual_flight_minutes', 213)->assertJsonPath('all_time_totals.actual_night_minutes', 45)
            ->assertJsonPath('all_time_totals.actual_work_minutes', 350)->assertJsonPath('all_time_totals.flights', 2);
        $this->getJson('/api/flight-time?date_from=2027-01-01')->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('all_time_totals.actual_flight_minutes', 213)->assertJsonPath('all_time_totals.flights', 2);
        $empty = $this->actingAs($this->admin, 'sanctum')->getJson('/api/flight-time')->assertOk();
        foreach ([...FlightTimeEntry::MINUTES, 'flights'] as $field) $empty->assertJsonPath('all_time_totals.'.$field, 0);
    }

    public function test_page_size_can_exceed_500_and_all_respects_filters_and_own_user(): void
    {
        $this->seedRows($this->tester, array_map(fn ($position) => $this->row('2026-10-30', $position, 1), range(1, 501)));
        $this->seedRows($this->tester, [$this->row('2026-09-30', 1, 10)]);
        $this->seedRows($this->ordinary, [$this->row('2026-10-30', 1, 100)]);
        $this->actingAs($this->tester, 'sanctum')->getJson('/api/flight-time?per_page=1000')
            ->assertOk()->assertJsonCount(502, 'data')->assertJsonPath('total', 502);
        $this->getJson('/api/flight-time?per_page=all&page=2&date_from=2026-10-01&group_by=entry_month')
            ->assertOk()->assertJsonCount(501, 'data')->assertJsonPath('total', 501)
            ->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 1)
            ->assertJsonPath('totals.actual_flight_minutes', 501)
            ->assertJsonPath('group_totals.0.totals.actual_flight_minutes', 501);
        $this->getJson('/api/flight-time?per_page=all&date_from=2027-01-01')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('total', 0);
        foreach (['0', '-1', 'invalid', '1.5'] as $size) {
            $this->getJson('/api/flight-time?per_page='.$size)->assertUnprocessable();
        }
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
