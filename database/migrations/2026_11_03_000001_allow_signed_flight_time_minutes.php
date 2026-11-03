<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const FIELDS = ['actual_flight_minutes', 'actual_night_minutes', 'actual_work_minutes',
        'planned_flight_minutes', 'planned_air_work_minutes', 'planned_ground_work_minutes',
        'forecast_flight_minutes', 'forecast_air_work_minutes', 'forecast_ground_work_minutes'];

    public function up(): void
    {
        Schema::table('flight_time_entries', function (Blueprint $table) {
            foreach (self::FIELDS as $field) $table->integer($field)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('flight_time_entries')->where(function ($query) {
            foreach (self::FIELDS as $field) $query->orWhere($field, '<', 0);
        })->exists()) {
            throw new RuntimeException('Cannot restore unsigned flight-time columns while negative durations exist.');
        }
        Schema::table('flight_time_entries', function (Blueprint $table) {
            foreach (self::FIELDS as $field) $table->unsignedInteger($field)->nullable()->change();
        });
    }
};
