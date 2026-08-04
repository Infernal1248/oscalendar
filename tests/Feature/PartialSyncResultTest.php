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
                'crew' => [['full_name' => 'Test Person']],
                'deferred_items' => [['title' => 'Test deferred item']],
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

        $this->withToken($this->token)
            ->postJson('/api/internal/sync-runs/'.$syncRun->id.'/finish', [
                'status' => 'finished',
                'stats' => ['items_found' => 1, 'segments_found' => 1],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'finished');

        $this->assertSame('finished', $syncRun->fresh()->status);
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
