<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRosterItemsTable extends Migration
{
    public function up()
    {
        Schema::create('roster_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 64)->default('rossiya_edu');
            $table->string('source_external_id', 64)->nullable();
            $table->text('source_request_raw')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('kind', 32)->default('other');
            $table->string('title', 150)->nullable();
            $table->string('aircraft_type_raw', 150)->nullable();
            $table->string('flight_numbers_raw', 255)->nullable();
            $table->string('boards_raw', 255)->nullable();
            $table->text('route_raw')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_actual')->default(true);
            $table->boolean('is_removed_from_source')->default(false);
            $table->json('source_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_at'], 'roster_items_user_starts_idx');
            $table->index(['user_id', 'source_external_id'], 'roster_items_user_external_idx');
            $table->unique(['user_id', 'source', 'source_external_id'], 'roster_items_user_source_external_unique');
            $table->unique(['user_id', 'source', 'source_hash'], 'roster_items_user_source_hash_unique');
            $table->index(['user_id', 'is_actual', 'starts_at'], 'roster_items_user_actual_starts_idx');
            $table->index('source_hash', 'roster_items_source_hash_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('roster_items');
    }
}
