<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrj_express_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_number', 64)->index();
            $table->string('event_text', 1000);
            $table->unsignedInteger('report_event_count')->nullable();
            $table->date('flight_date')->index();
            $table->string('flight_number', 64)->index();
            $table->string('aircraft_registration', 64)->index();
            $table->string('duration', 255)->nullable();
            $this->crewAndImport($table);
        });
        Schema::create('green_zone_flights', function (Blueprint $table) {
            $table->id();
            $table->date('flight_date')->index();
            $table->string('flight_number', 64)->index();
            $table->string('aircraft_registration', 64)->index();
            $table->string('aircraft_type', 64)->nullable();
            $table->string('flight_id', 64)->index();
            foreach (['takeoff_pitch', 'max_pitch', 'max_roll', 'max_load', 'landing_pitch', 'landing_load', 'threshold_distance', 'threshold_time'] as $field) {
                $table->decimal($field, 20, 7)->nullable();
            }
            $this->crewAndImport($table);
        });
        foreach (['green-zone' => 'Зелёная зона', 'rrj-express' => 'RRJ-EXPRESS'] as $key => $name) {
            foreach (['reader' => ['view', 'read'], 'importer' => ['view', 'read', 'import']] as $role => $permissions) {
                DB::table('roles')->insert([
                    'key' => "$key-$role", 'name' => ($role === 'reader' ? 'Просмотр: ' : 'Импорт: ').$name,
                    'description' => 'Доступ к общей базе отчётов '.$name,
                    'permissions' => json_encode(array_map(fn ($permission) => "$key.$permission", $permissions)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function crewAndImport(Blueprint $table): void
    {
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
    }

    public function down(): void
    {
        DB::table('role_user')->whereIn('role_id', DB::table('roles')->whereIn('key', ['green-zone-reader', 'green-zone-importer', 'rrj-express-reader', 'rrj-express-importer'])->select('id'))->delete();
        DB::table('roles')->whereIn('key', ['green-zone-reader', 'green-zone-importer', 'rrj-express-reader', 'rrj-express-importer'])->delete();
        Schema::dropIfExists('green_zone_flights');
        Schema::dropIfExists('rrj_express_events');
    }
};
