<?php

namespace Tests\Feature;

use App\Models\AirFase;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AirFaseRenameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_09_17_140000_rename_deviations_to_airfase.php');
    }

    public function test_upgrade_preserves_rows_role_assignments_custom_names_and_permissions_and_can_roll_back(): void
    {
        $migration = $this->migration();
        $migration->down();
        $user = User::create(['permissions' => ['deviations.view', 'deviations.read', 'workplan.view']]);
        $reader = Role::where('key', 'deviations-reader')->sole();
        $importer = Role::where('key', 'deviations-importer')->sole();
        $importer->update(['name' => 'Мой импорт']);
        $custom = Role::create(['key' => 'custom-airfase-access', 'name' => 'Аналитик', 'permissions' => ['deviations.import', 'users.view']]);
        $user->roles()->sync([$reader->id, $custom->id]);
        $assignments = DB::table('role_user')->orderBy('user_id')->orderBy('role_id')->get()->toArray();
        $id = DB::table('deviations')->insertGetId([
            'event_number' => '1004', 'event_text' => 'Fixture', 'level' => 'Medium', 'flight_date' => '2026-09-17',
            'aircraft_type' => 'RRJ', 'aircraft_registration' => '89001', 'flight_number' => 'FV6000',
            'fingerprint' => str_repeat('a', 64), 'uploaded_by' => $user->id, 'source_filename' => 'report.xlsx',
            'created_at' => '2026-09-17 00:00:00', 'updated_at' => '2026-09-17 00:00:00',
        ]);
        $row = (array) DB::table('deviations')->find($id);
        Cache::forever('report-filter-options:v1:deviations', ['old']);
        Cache::forever('report-filter-options:v1:airfase', ['stale']);

        $migration->up();
        $migration->up(); // Safe retry after a successful or partially applied deployment.
        $this->assertFalse(Schema::hasTable('deviations'));
        $this->assertSame($row, (array) DB::table('airfase')->find($id));
        $this->assertSame($id, AirFase::sole()->id);
        $this->assertEquals($assignments, DB::table('role_user')->orderBy('user_id')->orderBy('role_id')->get()->toArray());
        $this->assertSame('airfase-reader', $reader->fresh()->key);
        $this->assertSame('Просмотр AirFASE', $reader->fresh()->name);
        $this->assertSame('Мой импорт', $importer->fresh()->name);
        $this->assertSame(['airfase.import', 'users.view'], $custom->fresh()->permissions);
        $this->assertSame(['airfase.view', 'airfase.read', 'workplan.view'], $user->fresh()->permissions);
        $this->assertEqualsCanonicalizing(['profile.view', 'airfase.view', 'airfase.read', 'airfase.import', 'users.view'], $user->fresh()->effectivePermissions());
        $this->actingAs($user->fresh())->getJson('/api/account')->assertOk()
            ->assertJsonPath('airfase_access.read', true)->assertJsonPath('airfase_access.import', true)
            ->assertJsonMissingPath('deviations_access');
        $this->assertNull(Cache::get('report-filter-options:v1:deviations'));
        $this->assertNull(Cache::get('report-filter-options:v1:airfase'));
        $this->assertTrue(Schema::hasIndex('airfase', 'airfase_fingerprint_unique', 'unique'));
        $this->assertTrue(Schema::hasIndex('airfase', 'airfase_pilot_personnel_number_index'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('airfase'));
        $this->assertSame($row, (array) DB::table('deviations')->find($id));
        $this->assertSame('Просмотр отклонений', $reader->fresh()->name);
        $this->assertSame('deviations-importer', $importer->fresh()->key);
        $this->assertSame('Мой импорт', $importer->fresh()->name);
        $this->assertSame(['deviations.import', 'users.view'], $custom->fresh()->permissions);
        $this->assertTrue(Schema::hasIndex('deviations', 'deviations_fingerprint_unique', 'unique'));
    }

    public function test_conflicting_role_is_rejected_before_schema_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        Role::create(['key' => 'airfase-reader', 'name' => 'Conflicting role', 'permissions' => []]);
        try {
            $migration->up();
            $this->fail('Expected conflicting roles to abort the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('conflicting role', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('deviations'));
        $this->assertFalse(Schema::hasTable('airfase'));
    }

    public function test_report_migration_chain_can_roll_back_and_be_reapplied(): void
    {
        $steps = DB::table('migrations')->where('migration', '>=', '2026_09_09')->count();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]));
        $this->assertFalse(Schema::hasTable('airfase'));
        $this->assertFalse(Schema::hasTable('deviations'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('airfase'));
        $this->assertSame(['airfase.view', 'airfase.read'], Role::where('key', 'airfase-reader')->sole()->permissions);
    }
}
