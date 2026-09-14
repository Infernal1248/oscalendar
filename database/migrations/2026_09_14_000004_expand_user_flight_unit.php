<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('unit_number', 255)->nullable()->change());
    }

    public function down(): void
    {
        if (DB::table('users')->whereRaw('LENGTH(unit_number) > 32')->exists()) {
            throw new RuntimeException('Cannot shorten flight unit names without losing data.');
        }
        Schema::table('users', fn (Blueprint $table) => $table->string('unit_number', 32)->nullable()->change());
    }
};
