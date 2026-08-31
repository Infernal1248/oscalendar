<?php

namespace Tests\Feature;

use App\Models\PortalCredential;
use App\Models\FlightSegment;
use App\Models\RosterChangeEvent;
use App\Models\RosterItem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required.');
        }

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_portal_credentials_login_and_permissions_protect_account_api(): void
    {
        $user = User::query()->create([
            'display_name' => 'Web User',
            'permissions' => ['dashboard.view', 'profile.view'],
        ]);
        PortalCredential::query()->create([
            'user_id' => $user->id,
            'portal' => 'rossiya_edu',
            'login' => '124312',
            'password_encrypted' => Crypt::encryptString('secret'),
            'status' => 'active',
        ]);

        $token = $this->postJson('/api/auth/login', ['login' => '124312', 'password' => 'secret'])
            ->assertOk()
            ->assertJsonPath('user.display_name', 'Web User')
            ->assertJsonPath('user.navigation.0', 'profile')
            ->assertJsonMissingPath('user.role')
            ->assertJsonMissingPath('user.permissions')
            ->json('token');

        $this->withToken($token)->getJson('/api/dashboard')->assertOk();
        $this->withToken($token)->getJson('/api/workplan')->assertForbidden();
        $this->postJson('/api/auth/login', ['login' => '124312', 'password' => 'wrong'])->assertUnprocessable();
    }

    public function test_separate_local_admin_can_login_without_portal_credentials(): void
    {
        User::query()->create([
            'display_name' => 'Administrator',
            'login' => 'oscalendar-admin',
            'password' => Hash::make('strong-password'),
            'role' => 'admin',
        ]);

        $this->postJson('/api/auth/login', [
            'login' => 'oscalendar-admin',
            'password' => 'strong-password',
        ])
            ->assertOk()
            ->assertJsonPath('user.navigation.1', 'admin.users')
            ->assertJsonMissingPath('user.role')
            ->assertJsonMissingPath('user.permissions');
    }

    public function test_only_admin_can_manage_user_status_and_permissions(): void
    {
        $admin = User::query()->create(['display_name' => 'Administrator', 'role' => 'admin']);
        $user = User::query()->create(['display_name' => 'Crew Member']);

        $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$user->id}", [
            'status' => 'blocked',
            'permissions' => [],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('permissions.0', 'profile.view');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'blocked',
        ]);
    }

    public function test_workplan_returns_segment_summary_and_loads_full_details_separately(): void
    {
        $user = User::query()->create(['display_name' => 'Crew Member']);
        $rosterItem = RosterItem::query()->create([
            'user_id' => $user->id,
            'source' => 'rossiya_edu',
            'source_external_id' => 'ring-1',
            'kind' => 'flight_ring',
            'starts_at' => now()->addDay(),
        ]);
        $segment = FlightSegment::query()->create([
            'user_id' => $user->id,
            'roster_item_id' => $rosterItem->id,
            'source' => 'rossiya_edu',
            'source_para_id' => 'ring-1',
            'flight_number' => 'FV1234',
            'starts_at' => now()->addDay(),
            'ofp_url' => 'https://edu.rossiya-airlines.com/ops/detail/ofp-1/',
        ]);
        $segment->crewMembers()->create([
            'role' => 'КВС',
            'full_name' => 'Иванов Иван Иванович',
            'phones' => ['+79990000000'],
        ]);

        $this->actingAs($user)->getJson('/api/workplan')
            ->assertOk()
            ->assertJsonStructure(['0' => ['updated_at']])
            ->assertJsonPath('0.segments.0.flight_number', 'FV1234')
            ->assertJsonMissingPath('0.segments.0.crew');

        $this->actingAs($user)->getJson("/api/workplan/flights/{$segment->id}")
            ->assertOk()
            ->assertJsonPath('crew.0.full_name', 'Иванов Иван Иванович')
            ->assertJsonPath('crew.0.phones.0', '+79990000000')
            ->assertJsonPath('ofp_url', 'https://edu.rossiya-airlines.com/ops/detail/ofp-1/');

        $this->actingAs($user)->patchJson('/api/account', ['timezone' => 'Asia/Krasnoyarsk'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Asia/Krasnoyarsk')
            ->assertJsonPath('calendar_url', fn ($value) => is_string($value) && str_ends_with($value, '.ics'));
    }

    public function test_user_can_view_roster_change_history(): void
    {
        $user = User::query()->create(['display_name' => 'Crew Member']);
        RosterChangeEvent::query()->create([
            'user_id' => $user->id,
            'source' => 'rossiya_edu',
            'period' => '2026-08',
            'change_hash' => str_repeat('a', 64),
            'status' => 'acknowledged',
            'changes' => [[
                'changed_fields' => [
                    'route_raw' => ['label' => 'Маршрут', 'before' => 'SVO - LED', 'after' => 'SVO - KZN'],
                ],
            ]],
            'acknowledged_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/change-history')
            ->assertOk()
            ->assertJsonPath('0.period', '2026-08')
            ->assertJsonPath('0.status', 'acknowledged')
            ->assertJsonPath('0.changes.0.changed_fields.route_raw.after', 'SVO - KZN');
    }
}
