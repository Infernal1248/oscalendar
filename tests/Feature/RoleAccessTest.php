<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_roles_union_updates_and_admin_only_management(): void
    {
        $admin = User::create(['role' => 'admin']);
        $user = User::create([]);
        $this->assertTrue($user->hasPermission('workplan.view'));
        $this->assertFalse($user->hasPermission('deviations.view'));
        $this->actingAs($user)->getJson('/api/admin/roles')->assertForbidden();
        $this->getJson('/api/admin/permissions')->assertForbidden();
        $this->postJson('/api/admin/roles', ['name' => 'Forged', 'permissions' => []])->assertForbidden();

        $role = $this->actingAs($admin)->postJson('/api/admin/roles', [
            'name' => 'Аналитик', 'description' => 'Просмотр отчётов', 'permissions' => ['deviations.read'],
        ])->assertCreated()->json();
        $this->assertContains('deviations.view', $role['permissions']);
        $basic = Role::where('key', 'user')->sole();
        $this->patchJson("/api/admin/users/{$user->id}", ['role_ids' => [$basic->id, $role['id']]])->assertOk();
        $this->assertTrue($user->fresh()->hasPermission('workplan.view'));
        $this->assertTrue($user->fresh()->hasPermission('deviations.read'));
        $this->actingAs($user->fresh())->getJson('/api/deviations')->assertOk();

        $this->actingAs($admin)->deleteJson('/api/admin/roles/'.$role['id'])->assertUnprocessable();
        $this->patchJson('/api/admin/roles/'.$role['id'], ['name' => 'Аналитик', 'permissions' => []])->assertOk();
        $this->actingAs($user->fresh())->getJson('/api/deviations')->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/admin/users/{$user->id}", ['role_ids' => [$basic->id]])->assertOk();
        $this->deleteJson('/api/admin/roles/'.$role['id'])->assertOk();

        foreach (['roles.manage', 'unknown.permission'] as $permission) {
            $this->postJson('/api/admin/roles', ['name' => 'Escalation', 'permissions' => [$permission]])->assertUnprocessable();
        }
        $this->patchJson("/api/admin/users/{$user->id}", ['status' => 'active', 'permissions' => []])->assertUnprocessable();
        $this->patchJson("/api/admin/users/{$user->id}", ['role_ids' => [99999]])->assertUnprocessable();
        $this->patchJson("/api/admin/users/{$user->id}", ['role_ids' => [$basic->id, $basic->id]])->assertUnprocessable();
        $this->postJson('/api/admin/roles', ['name' => $basic->name, 'permissions' => []])->assertUnprocessable();
    }

    public function test_user_viewers_and_managers_cannot_assign_roles_or_manage_admins(): void
    {
        $admin = User::create(['role' => 'admin']);
        $user = User::create(['status' => 'pending']);
        $viewer = User::create([]);
        $viewer->roles()->sync([Role::where('key', 'users-reader')->sole()->id]);
        $this->actingAs($viewer)->getJson('/api/admin/users')->assertOk();
        $this->patchJson("/api/admin/users/{$user->id}", ['status' => 'active'])->assertForbidden();
        $viewer->roles()->sync([Role::where('key', 'users-manager')->sole()->id]);
        $this->actingAs($viewer->fresh())->patchJson("/api/admin/users/{$user->id}", ['status' => 'active'])->assertOk();
        $this->assertTrue($user->fresh()->hasPermission('workplan.view'));
        $this->patchJson("/api/admin/users/{$viewer->id}", ['role_ids' => [Role::where('key', 'administrator')->sole()->id]])->assertForbidden();
        $this->patchJson("/api/admin/users/{$admin->id}", ['status' => 'blocked'])->assertForbidden();
        $this->getJson('/api/admin/roles')->assertForbidden();
        $this->getJson('/api/admin/permissions')->assertForbidden();
        $roleId = Role::where('key', 'users-reader')->sole()->id;
        $this->patchJson("/api/admin/roles/{$roleId}", ['name' => 'Changed', 'permissions' => []])->assertForbidden();
        $this->deleteJson("/api/admin/roles/{$roleId}")->assertForbidden();
    }

    public function test_protected_roles_last_admin_and_blocked_accounts(): void
    {
        $admin = User::create(['role' => 'admin']);
        $role = Role::where('key', 'administrator')->sole();
        $this->actingAs($admin)->patchJson("/api/admin/roles/{$role->id}", ['name' => 'Changed', 'permissions' => []])->assertUnprocessable();
        $this->deleteJson("/api/admin/roles/{$role->id}")->assertUnprocessable();
        $this->deleteJson('/api/admin/roles/'.Role::where('key', 'user')->sole()->id)->assertUnprocessable();
        $this->patchJson("/api/admin/users/{$admin->id}", ['role_ids' => []])->assertUnprocessable();
        $this->patchJson("/api/admin/users/{$admin->id}", ['status' => 'blocked'])->assertUnprocessable();
        $other = User::create([]);
        $this->patchJson("/api/admin/users/{$other->id}", ['role_ids' => [$role->id]])->assertOk();
        $this->assertTrue($other->fresh()->isAdmin());
        $this->patchJson("/api/admin/users/{$admin->id}", ['role_ids' => []])->assertOk();
        // The legacy users.role field must not restore removed administrator privileges.
        $this->actingAs($admin->fresh())->getJson('/api/admin/roles')->assertForbidden();
        $tokenId = $admin->createToken('web')->accessToken->id;
        $this->actingAs($other->fresh())->patchJson("/api/admin/users/{$admin->id}", ['status' => 'blocked'])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->actingAs($admin->fresh())->getJson('/api/account')->assertForbidden();
        $this->getJson('/api/workplan')->assertForbidden();
        $this->patchJson('/api/account', ['timezone' => 'UTC'])->assertForbidden();
    }

    public function test_migration_preserves_exact_access_without_granting_extra_roles(): void
    {
        // Simulate a pre-upgrade database, using raw records (no new-user role hook).
        Schema::drop('role_user');
        Schema::drop('roles');
        $sets = [null, [], ['deviations.view', 'deviations.read'], ['workplan.view'], ['deviations.view', 'deviations.read']];
        $ids = [];
        foreach ($sets as $permissions) {
            $ids[] = DB::table('users')->insertGetId([
                'status' => 'active', 'role' => 'user', 'permissions' => $permissions === null ? null : json_encode($permissions),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $adminId = DB::table('users')->insertGetId(['status' => 'active', 'role' => 'admin', 'login' => 'admin']);
        $migration = require database_path('migrations/2026_09_09_000001_create_access_roles.php');
        $migration->up();
        foreach ($ids as $i => $id) {
            $expected = array_unique(['profile.view', ...($sets[$i] ?? config('permissions.defaults'))]);
            $this->assertEqualsCanonicalizing($expected, User::findOrFail($id)->effectivePermissions());
        }
        $this->assertTrue(User::findOrFail($adminId)->isAdmin());
        $this->assertSame(User::findOrFail($ids[2])->roles->sole()->id, User::findOrFail($ids[4])->roles->sole()->id);
        $this->assertFalse(User::findOrFail($ids[0])->hasPermission('deviations.view'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('roles'));
        $this->assertSame('admin', DB::table('users')->where('id', $adminId)->value('role'));
    }

    public function test_console_can_grant_administrator_to_an_existing_local_account(): void
    {
        $user = User::create(['login' => 'local-admin', 'status' => 'active']);
        $this->artisan('account:make-admin', ['login' => 'local-admin'])
            ->expectsQuestion('Password (minimum 12 characters)', 'test-long-password')
            ->expectsQuestion('Confirm password', 'test-long-password')
            ->assertSuccessful();
        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertDatabaseCount('users', 1);
    }
}
