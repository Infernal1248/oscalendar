<?php

namespace Tests\Feature;

use App\Models\PortalCredential;
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
}
