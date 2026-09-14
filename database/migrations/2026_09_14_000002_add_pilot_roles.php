<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('unit_number', 32)->nullable());
        foreach (['pilot' => 'Рядовой пилот', 'unit-head' => 'Руководитель подразделения', 'senior-leader' => 'Старший руководитель'] as $key => $name) {
            DB::table('roles')->insert([
                'key' => $key, 'name' => $name, 'description' => 'Должность. Не предоставляет дополнительных разрешений.',
                'permissions' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $keys = ['pilot', 'unit-head', 'senior-leader'];
        DB::table('role_user')->whereIn('role_id', DB::table('roles')->whereIn('key', $keys)->select('id'))->delete();
        DB::table('roles')->whereIn('key', $keys)->delete();
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('unit_number'));
    }
};
