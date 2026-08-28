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
            ->assertJsonPath('user.permissions.0', 'dashboard.view')
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
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.permissions.3', 'users.manage');
    }
}
