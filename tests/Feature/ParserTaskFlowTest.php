<?php

namespace Tests\Feature;

use App\Models\InternalApiToken;
use App\Models\ParserTask;
use App\Models\PortalCredential;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
                    'ends_at' => now()->addHours(14)->toIso8601String(),
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
        $this->assertSame(2, ParserTask::query()->count());
    }

    private function claim()
    {
        return $this->withToken($this->token)->postJson('/api/internal/parser-jobs/claim', [
            'source' => 'rossiya_edu',
            'portal' => 'rossiya_edu',
            'locked_by' => 'test-supervisor',
            'lock_seconds' => 900,
            'capabilities' => ['typed_tasks_v1'],
        ]);
    }
}
