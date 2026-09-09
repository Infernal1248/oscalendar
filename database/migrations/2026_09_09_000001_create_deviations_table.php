<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deviations', function (Blueprint $table) {
            $table->id();
            $table->string('event_number', 64)->index();
            $table->string('event_text', 1000);
            $table->unsignedInteger('report_event_count')->nullable();
            $table->string('level', 64);
            $table->date('flight_date')->index();
            $table->string('aircraft_type', 64);
            $table->string('aircraft_registration', 64)->index();
            $table->string('flight_number', 64)->index();
            $table->string('parameter', 1000)->nullable();
            $table->string('parameter_value', 255)->nullable();
            $table->string('captain_name')->nullable();
            $table->string('captain_code', 64)->nullable();
            $table->string('pilot_name')->nullable();
            $table->string('pilot_personnel_number', 64)->nullable();
            $table->string('pilot_position')->nullable();
            $table->string('flight_unit')->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_filename');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deviations');
    }
};
