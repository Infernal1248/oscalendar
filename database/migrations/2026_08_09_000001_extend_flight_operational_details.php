<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExtendFlightOperationalDetails extends Migration
{
    public function up()
    {
        Schema::table('flight_crew_members', function (Blueprint $table) {
            $table->string('personnel_number', 32)->nullable()->after('full_name');
            $table->string('crew_group', 32)->nullable()->after('personnel_number');
            $table->string('department', 180)->nullable()->after('crew_group');
            $table->string('position', 100)->nullable()->after('department');
            $table->string('qualification', 100)->nullable()->after('position');
            $table->string('seniority', 50)->nullable()->after('qualification');
            $table->text('training_notes')->nullable()->after('seniority');
            $table->json('source_payload')->nullable()->after('phones');
        });

        Schema::table('flight_deferred_items', function (Blueprint $table) {
            $table->dateTime('issued_at')->nullable()->after('work_order');
            $table->string('mel', 100)->nullable()->after('due_at');
            $table->string('tah', 50)->nullable()->after('mel');
            $table->string('tac', 50)->nullable()->after('tah');
        });
    }

    public function down()
    {
        Schema::table('flight_deferred_items', function (Blueprint $table) {
            $table->dropColumn(['issued_at', 'mel', 'tah', 'tac']);
        });

        Schema::table('flight_crew_members', function (Blueprint $table) {
            $table->dropColumn([
                'personnel_number',
                'crew_group',
                'department',
                'position',
                'qualification',
                'seniority',
                'training_notes',
                'source_payload',
            ]);
        });
    }
}
