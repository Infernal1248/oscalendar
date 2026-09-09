<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 150)->unique();
            $table->text('description')->nullable();
            $table->json('permissions');
            $table->timestamps();
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
            $table->index('user_id');
        });

        $defaults = ['dashboard.view', 'profile.view', 'workplan.view', 'history.view'];
        $presets = [
            'administrator' => ['Администратор', array_keys(config('permissions.catalog'))],
            'user' => ['Пользователь', $defaults],
            'deviations-reader' => ['Просмотр отклонений', ['deviations.view', 'deviations.read']],
            'deviations-importer' => ['Импорт отклонений', ['deviations.view', 'deviations.read', 'deviations.import']],
            'users-reader' => ['Просмотр пользователей', ['users.view']],
            'users-manager' => ['Управление пользователями', ['users.view', 'users.manage']],
        ];
        DB::transaction(function () use ($defaults, $presets) {
            $ids = [];
            foreach ($presets as $key => [$name, $permissions]) {
                $ids[$key] = DB::table('roles')->insertGetId([
                    'key' => $key, 'name' => $name, 'permissions' => json_encode($permissions),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // Preserve exact legacy access, including explicit restrictions; do not add defaults to everyone.
            $legacyKeys = [...$defaults, 'deviations.view', 'deviations.read', 'deviations.import'];
            $migrated = [];
            DB::table('users')->orderBy('id')->chunkById(200, function ($users) use ($ids, $defaults, $legacyKeys, &$migrated) {
                foreach ($users as $user) {
                    $permissions = $user->permissions === null ? $defaults : json_decode($user->permissions, true, 512, JSON_THROW_ON_ERROR);
                    $permissions = array_values(array_unique(['profile.view', ...array_intersect($permissions, $legacyKeys)]));
                    sort($permissions);
                    $base = $defaults;
                    sort($base);
                    if ($user->role === 'admin') {
                        $roleId = $ids['administrator'];
                    } elseif ($permissions === $base) {
                        $roleId = $ids['user'];
                    } else {
                        $signature = json_encode($permissions);
                        $roleId = $migrated[$signature] ??= DB::table('roles')->insertGetId([
                            'key' => 'migrated-'.hash('sha256', $signature),
                            'name' => 'Перенесённые права #'.$user->id,
                            'description' => 'Сохранённый набор прав до перехода на роли. Можно переименовать или заменить назначением других ролей.',
                            'permissions' => $signature, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id]);
                }
            });
        });
    }

    public function down(): void
    {
        // Keep current effective access when rolling back, rather than restoring stale individual permissions.
        DB::table('users')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $roles = DB::table('roles')->join('role_user', 'roles.id', '=', 'role_user.role_id')->where('user_id', $user->id)->get();
                $permissions = $roles->flatMap(fn ($role) => json_decode($role->permissions, true))->push('profile.view')->unique()->values()->all();
                DB::table('users')->where('id', $user->id)->update([
                    'role' => $roles->contains('key', 'administrator') ? 'admin' : 'user',
                    'permissions' => json_encode($permissions),
                ]);
            }
        });
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};
