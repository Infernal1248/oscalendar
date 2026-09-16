<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('source', 64);
            $table->string('full_name');
            $table->string('personnel_number', 64);
            // Small portal avatars stay private and are backed up with their profile.
            $table->mediumText('photo_base64')->nullable();
            $table->string('photo_mime', 32)->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
        });
        foreach (['deviations', 'green_zone_flights', 'rrj_express_events'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->index('pilot_personnel_number');
                $table->index('captain_code');
                $table->index('flight_unit');
            });
        }
        foreach (['pilot' => 'свои строки по ФИО и табельному номеру', 'unit-head' => 'строки назначенного лётного отряда', 'senior-leader' => 'строки всех лётных отрядов'] as $key => $scope) {
            DB::table('roles')->where('key', $key)->where('description', 'Должность. Не предоставляет дополнительных разрешений.')
                ->update(['description' => 'Область отчётов: '.$scope.'. Доступ к таблицам назначается отдельно.']);
        }
    }

    public function down(): void
    {
        foreach (['deviations', 'green_zone_flights', 'rrj_express_events'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['pilot_personnel_number']);
                $table->dropIndex(['captain_code']);
                $table->dropIndex(['flight_unit']);
            });
        }
        Schema::dropIfExists('portal_profiles');
    }
};
