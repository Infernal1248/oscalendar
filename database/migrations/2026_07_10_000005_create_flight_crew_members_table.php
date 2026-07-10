<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFlightCrewMembersTable extends Migration
{
    public function up()
    {
        Schema::create('flight_crew_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_segment_id')->constrained('flight_segments')->cascadeOnDelete();
            $table->string('role', 50)->nullable();
            $table->string('full_name', 180);
            $table->json('phones')->nullable();
            $table->timestamps();

            $table->index('flight_segment_id', 'flight_crew_members_segment_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('flight_crew_members');
    }
}
