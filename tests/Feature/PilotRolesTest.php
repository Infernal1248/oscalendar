<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Deviation;
use App\Models\GreenZone;
use App\Models\RrjExpress;
use App\Services\FlightUnitDirectory;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PilotRolesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Cache::forget(FlightUnitDirectory::CACHE_KEY);
    }

    private function reportUnit(string $model, ?string $unit): void
    {
        $record = array_fill_keys($model::REQUIRED, 'test');
        $record['flight_date'] = '2026-09-14';
        $model::query()->insert($record + ['flight_unit' => $unit, 'fingerprint' => hash('sha256', uniqid('', true)), 'source_filename' => 'test.xlsx']);
    }

    public function test_flight_units_are_shared_unique_cached_and_not_public(): void
    {
        $manager = $this->grantPermissions(User::create(['status' => 'active']), ['users.view', 'users.manage']);
        $this->actingAs($manager)->getJson('/api/admin/flight-units')->assertOk()->assertExactJson([]);
        Cache::forget(FlightUnitDirectory::CACHE_KEY);
        $this->reportUnit(Deviation::class, ' Петербург ');
        $this->reportUnit(GreenZone::class, 'Петербург');
        $this->reportUnit(RrjExpress::class, 'Москва');
        $this->reportUnit(Deviation::class, '');
        $this->reportUnit(GreenZone::class, null);
        DB::enableQueryLog();
        $this->assertSame(['Москва', 'Петербург'], FlightUnitDirectory::values());
        $this->assertCount(1, DB::getQueryLog());
        DB::flushQueryLog();
        $this->assertSame(['Москва', 'Петербург'], FlightUnitDirectory::values());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->reportUnit(Deviation::class, 'Красноярск');
        $this->assertNotContains('Красноярск', FlightUnitDirectory::values());
        $this->travel(30)->days();
        try {
            $this->assertNotContains('Красноярск', FlightUnitDirectory::values());
            FlightUnitDirectory::invalidate();
            $this->assertContains('Красноярск', FlightUnitDirectory::values());
        } finally {
            $this->travelBack();
        }
        $this->actingAs(User::create(['status' => 'active']))->getJson('/api/admin/flight-units')->assertForbidden();
        $viewer = $this->grantPermissions(User::create(['status' => 'active']), ['users.view']);
        $this->actingAs($viewer)->getJson('/api/admin/flight-units')->assertForbidden();
    }

    public function test_managers_can_classify_without_granting_permissions_or_disclosing_self_roles(): void
    {
        $manager = $this->grantPermissions(User::create(['status' => 'active']), ['users.view', 'users.manage']);
        $target = User::create(['status' => 'pending']);
        $path = '/api/admin/users/'.$target->id;
        $unit = 'Лётный отряд Санкт-Петербург — командный состав';
        $this->reportUnit(Deviation::class, $unit);
        $this->actingAs($manager)->getJson('/api/admin/pilot-roles')->assertOk()->assertJsonCount(3);
        $this->patchJson($path, ['status' => 'active'])->assertOk();
        $this->assertNull($target->fresh()->pilotRole());
        $this->patchJson($path, ['status' => 'active', 'pilot_role' => 'administrator'])->assertUnprocessable();
        $this->patchJson($path, ['status' => 'active', 'pilot_role' => 'unit-head'])->assertUnprocessable();
        $this->patchJson($path, ['status' => 'active', 'pilot_role' => 'unit-head', 'unit_number' => '<bad>'])->assertUnprocessable();
        $this->assertSame('active', $target->fresh()->status);
        $this->patchJson($path, ['status' => 'active', 'pilot_role' => 'unit-head', 'unit_number' => $unit])
            ->assertOk()->assertJsonPath('pilot_role', 'unit-head')->assertJsonPath('unit_number', $unit);
        $this->assertEqualsCanonicalizing(['profile.view', 'dashboard.view', 'workplan.view', 'history.view'], $target->fresh()->effectivePermissions());
        $this->patchJson($path, ['role_ids' => [Role::where('key', 'administrator')->sole()->id]])->assertForbidden();
        $this->patchJson($path, ['pilot_role' => 'senior-leader'])->assertOk()->assertJsonPath('unit_number', null);
        $this->assertCount(1, $target->fresh()->roles->filter(fn ($role) => $role->isPilotRole()));
        $this->actingAs($target->fresh())->getJson('/api/account')->assertOk()->assertJsonMissingPath('roles')
            ->assertJsonMissingPath('pilot_role')->assertJsonMissingPath('unit_number');
        $this->getJson('/api/admin/pilot-roles')->assertForbidden();
        $this->patchJson('/api/account', ['timezone' => 'UTC', 'pilot_role' => 'unit-head', 'unit_number' => '99'])->assertOk();
        $this->assertSame('senior-leader', $target->fresh()->pilotRole());

        $admin = User::create(['role' => 'admin', 'status' => 'active']);
        $role = Role::where('key', 'senior-leader')->sole();
        $this->actingAs($admin)->getJson('/api/admin/roles')->assertOk()->assertJsonFragment(['is_pilot_role' => true, 'editable' => false]);
        $this->patchJson('/api/admin/roles/'.$role->id, ['name' => 'Senior', 'permissions' => ['users.manage']])->assertUnprocessable();
        $this->deleteJson('/api/admin/roles/'.$role->id)->assertUnprocessable();
        $this->patchJson($path, ['role_ids' => [Role::where('key', 'deviations-reader')->sole()->id]])->assertOk();
        $this->assertSame('senior-leader', $target->fresh()->pilotRole());
        $this->assertTrue($target->fresh()->hasPermission('deviations.read'));
    }
}
