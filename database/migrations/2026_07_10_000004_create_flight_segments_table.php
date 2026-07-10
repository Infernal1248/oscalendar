<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFlightSegmentsTable extends Migration
{
    public function up()
    {
        Schema::create('flight_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('roster_item_id')->nullable()->constrained('roster_items')->nullOnDelete();
            $table->string('source', 64)->default('rossiya_edu');
            $table->string('source_para_id', 64)->nullable();
            $table->string('source_segment_id', 64)->nullable();
            $table->string('flight_number', 32)->nullable();
            $table->string('route_raw', 255)->nullable();
            $table->string('departure_name', 100)->nullable();
            $table->string('arrival_name', 100)->nullable();
            $table->string('aircraft_type', 64)->nullable();
            $table->string('board', 32)->nullable();
            $table->string('purpose', 16)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('parking_minutes')->nullable();
            $table->string('dep_stand', 32)->nullable();
            $table->string('arr_stand', 32)->nullable();
            $table->text('open_doc_url')->nullable();
            $table->text('download_doc_url')->nullable();
            $table->dateTime('next_update_at')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_at'], 'flight_segments_user_starts_idx');
            $table->index('roster_item_id', 'flight_segments_roster_item_idx');
            $table->index('source_para_id', 'flight_segments_source_para_idx');
            $table->index(
                ['user_id', 'source_para_id', 'flight_number', 'starts_at'],
                'flight_segments_identity_idx'
            );
            $table->index(['user_id', 'next_update_at'], 'flight_segments_user_next_update_idx');
            $table->index('source_hash', 'flight_segments_source_hash_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('flight_segments');
    }
}
