<?php

namespace Tests\Feature;

use App\Models\FlightSegment;
use App\Models\InternalApiToken;
use App\Models\RosterItem;
use App\Models\SyncRun;
use App\Models\SyncRunPartialChunk;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class PartialSyncResultTest extends TestCase
{
    private string $token = 'partial-sync-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        InternalApiToken::query()->create([
            'name' => 'partial sync test',
            'token_hash' => InternalApiToken::hashToken($this->token),
            'is_active' => true,
        ]);
    }

    public function test_partial_chunks_are_idempotent_and_finish_closes_the_run(): void
    {
        $user = User::query()->create(['display_name' => 'Parser Test']);
        $syncRun = SyncRun::query()->create([
            'user_id' => $user->id,
            'source' => 'rossiya_edu',
            'trigger' => 'scheduler',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $rosterPayload = $this->basePayload($syncRun, [
            'chunk_kind' => 'roster',
            'roster_items' => [[
                'source_external_id' => '1631777',
                'kind' => 'flight',
                'title' => 'Test flight',
                'starts_at' => '2026-08-04T10:00:00Z',
            ]],
        ]);

        $this->postChunk($syncRun, $rosterPayload)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'sync_run_id' => $syncRun->id,
                'chunk_kind' => 'roster',
                'stats' => ['items_found' => 1, 'segments_found' => 0],
            ]);
        $this->postChunk($syncRun, $rosterPayload)->assertOk();

        $flightPayload = $this->basePayload($syncRun, [
            'chunk_kind' => 'flight_segments',
            'roster_source_external_id' => '1631777',
            'flight_segments' => [[
                'source_para_id' => 'para-1',
                'flight_number' => 'FV123',
                'starts_at' => '2026-08-04T10:00:00Z',
                'ofp_url' => 'https://edu.rossiya-airlines.com/ops/detail/ofp-1/',
                'crew' => [[
                    'role' => 'КВС',
                    'full_name' => 'Test Person',
                    'personnel_number' => '124312',
                    'crew_group' => 'flight',
                    'department' => 'Flight crew',
                    'position' => 'Captain',
                    'qualification' => '+',
                    'seniority' => '10',
                    'training_notes' => 'Training note',
                    'phones' => ['+79990000000'],
                    'source_payload' => ['sources' => ['workplan', 'ops']],
                ]],
                'deferred_items' => [[
                    'title' => 'Test deferred item',
                    'work_order' => 'WO1',
                    'issued_at' => '2026-08-01T00:00:00Z',
                    'due_at' => '2026-12-01T00:00:00Z',
                    'mel' => 'D 25-43-00',
                    'tah' => '13988',
                    'tac' => '7569',
                ]],
            ]],
        ]);

        $this->postChunk($syncRun, $flightPayload)
            ->assertOk()
            ->assertJsonPath('stats.items_found', 1)
            ->assertJsonPath('stats.segments_found', 1);
        $this->postChunk($syncRun, $flightPayload)->assertOk();

        $syncRun->refresh();
        $segment = FlightSegment::query()->firstOrFail();

        $this->assertSame('running', $syncRun->status);
        $this->assertSame(1, $syncRun->items_found);
        $this->assertSame(1, $syncRun->segments_found);
        $this->assertSame(1, RosterItem::query()->count());
        $this->assertSame(1, FlightSegment::query()->count());
        $this->assertSame(2, SyncRunPartialChunk::query()->count());
        $this->assertNotNull($segment->roster_item_id);
        $this->assertSame(1, $segment->crewMembers()->count());
        $this->assertSame(1, $segment->deferredItems()->count());
        $this->assertSame('124312', $segment->crewMembers()->first()->personnel_number);
        $this->assertSame(['+79990000000'], $segment->crewMembers()->first()->phones);
        $this->assertSame('D 25-43-00', $segment->deferredItems()->first()->mel);
        $this->assertSame('13988', $segment->deferredItems()->first()->tah);
        $this->assertSame('https://edu.rossiya-airlines.com/ops/detail/ofp-1/', $segment->ofp_url);

        $flightWithoutOfp = $flightPayload;
        unset($flightWithoutOfp['flight_segments'][0]['ofp_url']);
        $flightWithoutOfp['parsed_at'] = '2026-08-04T09:30:08Z';
        $this->postChunk($syncRun, $flightWithoutOfp)->assertOk();
        $this->assertSame('https://edu.rossiya-airlines.com/ops/detail/ofp-1/', $segment->fresh()->ofp_url);

        $shiftedFlight = $flightPayload;
        $shiftedFlight['parsed_at'] = '2026-08-04T09:40:08Z';
        $shiftedFlight['flight_segments'][0]['starts_at'] = '2026-08-04T10:15:00Z';
        $this->postChunk($syncRun, $shiftedFlight)->assertOk();
        $this->assertSame(1, FlightSegment::query()->count());
        $this->assertDatabaseMissing('flight_segments', ['starts_at' => '2026-08-04 10:00:00']);
        $this->assertDatabaseHas('flight_segments', ['starts_at' => '2026-08-04 10:15:00']);

        $this->withToken($this->token)
            ->postJson('/api/internal/sync-runs/'.$syncRun->id.'/finish', [
                'status' => 'finished',
                'stats' => ['items_found' => 1, 'segments_found' => 1],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'finished');

        $this->assertSame('finished', $syncRun->fresh()->status);
    }

    public function test_corrected_overnight_date_replaces_old_segment_and_wrong_crew(): void
    {
        $user = User::create(['display_name' => 'Overnight test']);
        $run = SyncRun::create(['user_id' => $user->id, 'source' => 'rossiya_edu', 'trigger' => 'scheduler', 'status' => 'running', 'started_at' => now()]);
        $this->postChunk($run, $this->basePayload($run, ['roster_items' => [[
            'source_external_id' => 'overnight-ring', 'kind' => 'flight_ring', 'starts_at' => '2026-09-16T20:30:00Z',
        ]]]))->assertOk();
        $payload = $this->basePayload($run, [
            'chunk_kind' => 'flight_segments', 'roster_source_external_id' => 'overnight-ring',
            'flight_segments' => [
                ['source_para_id' => 'overnight-ring', 'flight_number' => 'FV6805', 'starts_at' => '2026-09-16T20:30:00Z', 'ends_at' => '2026-09-16T21:55:00Z'],
                ['source_para_id' => 'overnight-ring', 'flight_number' => 'FV6806', 'starts_at' => '2026-09-16T04:00:00Z', 'ends_at' => '2026-09-16T05:30:00Z', 'crew' => [['full_name' => 'Wrong Day Crew']]],
            ],
        ]);
        $this->postChunk($run, $payload)->assertOk();
        $oldId = FlightSegment::where('flight_number', 'FV6806')->sole()->id;
        $payload['flight_segments'][1]['starts_at'] = '2026-09-17T04:00:00Z';
        $payload['flight_segments'][1]['ends_at'] = '2026-09-17T05:30:00Z';
        $payload['flight_segments'][1]['crew'] = [['full_name' => 'Correct Day Crew']];
        $this->postChunk($run, $payload)->assertOk();

        $this->assertDatabaseCount('flight_segments', 2);
        $this->assertDatabaseMissing('flight_segments', ['id' => $oldId]);
        $this->assertDatabaseMissing('flight_crew_members', ['full_name' => 'Wrong Day Crew']);
        $return = FlightSegment::where('flight_number', 'FV6806')->sole();
        $this->assertSame('2026-09-17 04:00:00', $return->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('Correct Day Crew', $return->crewMembers()->sole()->full_name);
        $this->assertSame('FV6805', FlightSegment::withActualRosterItem()->orderBy('starts_at')->first()->flight_number);
    }

    private function postChunk(SyncRun $syncRun, array $payload)
    {
        return $this->withToken($this->token)
            ->postJson('/api/internal/sync-runs/'.$syncRun->id.'/partial-result', $payload);
    }

    private function basePayload(SyncRun $syncRun, array $overrides): array
    {
        return array_merge([
            'sync_run_id' => $syncRun->id,
            'user_id' => $syncRun->user_id,
            'source' => $syncRun->source,
            'trigger' => $syncRun->trigger,
            'parsed_at' => '2026-08-04T09:20:08Z',
            'chunk_kind' => 'roster',
            'is_final' => false,
            'roster_source_external_id' => null,
            'roster_items' => [],
            'flight_segments' => [],
        ], $overrides);
    }
}
