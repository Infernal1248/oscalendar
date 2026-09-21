<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', fn (Blueprint $table) => $table->string('tier', 16)->default('extended'));
        Schema::create('subscription_prices', function (Blueprint $table) {
            $table->id();
            $table->string('tier', 16);
            $table->unsignedSmallInteger('days');
            $table->unsignedInteger('old_price_kopecks');
            $table->unsignedInteger('price_kopecks');
            $table->timestamps();
            $table->unique(['tier', 'days']);
        });
        DB::transaction(function () {
            foreach (['basic' => [[250, 250], [750, 700], [1500, 1300], [3000, 2500]],
                'extended' => [[300, 300], [900, 850], [1800, 1600], [3600, 3000]]] as $tier => $prices) {
                foreach ([30, 90, 180, 365] as $index => $days) {
                    DB::table('subscription_prices')->insert(['tier' => $tier, 'days' => $days,
                        'old_price_kopecks' => $prices[$index][0] * 100, 'price_kopecks' => $prices[$index][1] * 100,
                        'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $reportPermissions = ['airfase.view', 'airfase.read', 'green-zone.view', 'green-zone.read', 'rrj-express.view', 'rrj-express.read'];
            // Decide from the old permissions before removing subscription-controlled grants.
            foreach (DB::table('users')->get(['id', 'login']) as $user) {
                if (in_array($user->login, ['demo_basic', 'demo_premium'], true)) continue;
                $roles = DB::table('roles')->join('role_user', 'roles.id', '=', 'role_user.role_id')->where('role_user.user_id', $user->id)->get(['roles.key', 'roles.permissions']);
                $hasReports = $roles->contains(fn ($role) => $role->key === 'administrator'
                    || array_intersect(['airfase.read', 'green-zone.read', 'rrj-express.read'], json_decode($role->permissions, true) ?? []));
                DB::table('subscription_payments')->where('user_id', $user->id)->update(['tier' => $hasReports ? 'extended' : 'basic']);
            }
            foreach (DB::table('roles')->get() as $role) {
                if ($role->key === 'administrator') continue;
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_diff(json_decode($role->permissions, true) ?? [], $reportPermissions)))]);
            }
            $readerIds = DB::table('roles')->whereIn('key', ['airfase-reader', 'green-zone-reader', 'rrj-express-reader'])->get()
                ->filter(fn ($role) => empty(json_decode($role->permissions, true)))->pluck('id');
            DB::table('role_user')->whereIn('role_id', $readerIds)->delete();
            DB::table('roles')->whereIn('id', $readerIds)->delete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Миграция меняет оплаченные уровни и роли. Для отката восстановите согласованную резервную копию БД и кода.');
    }
};
