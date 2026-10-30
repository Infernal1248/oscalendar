<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flight_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->unsignedInteger('row_position');
            $table->string('flight_number', 100)->nullable();
            $table->string('description', 1000);
            $table->string('flight_assignment_number', 100)->nullable();
            foreach (['actual_flight_minutes', 'actual_night_minutes', 'actual_work_minutes',
                'planned_flight_minutes', 'planned_air_work_minutes', 'planned_ground_work_minutes',
                'forecast_flight_minutes', 'forecast_air_work_minutes', 'forecast_ground_work_minutes'] as $field) $table->unsignedInteger($field)->nullable();
            $table->json('source_payload');
            $table->foreignId('sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'entry_date', 'row_position'], 'flight_time_user_date_position_unique');
        });
        Schema::table('portal_profiles', function (Blueprint $table) {
            $table->string('nalet_user_id', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portal_profiles', fn (Blueprint $table) => $table->dropColumn('nalet_user_id'));
        Schema::dropIfExists('flight_time_entries');
    }
};
