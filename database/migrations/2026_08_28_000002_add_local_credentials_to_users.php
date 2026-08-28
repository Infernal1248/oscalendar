<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('login', 150)->nullable()->unique()->after('permissions');
            $table->string('password')->nullable()->after('login');
        });

        // Undo the earlier Telegram-admin backfill if the previous migration was already deployed.
        DB::table('users')->where('role', 'admin')->whereNull('login')->update(['role' => 'user']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['login']);
            $table->dropColumn(['login', 'password']);
        });
    }
};
