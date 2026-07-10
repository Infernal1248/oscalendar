<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFlightDeferredItemsTable extends Migration
{
    public function up()
    {
        Schema::create('flight_deferred_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_segment_id')->constrained('flight_segments')->cascadeOnDelete();
            $table->string('group_name', 150)->nullable();
            $table->text('title')->nullable();
            $table->string('ata', 50)->nullable();
            $table->string('work_order', 100)->nullable();
            $table->dateTime('due_at')->nullable();
            $table->boolean('is_warning')->default(false);
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index('flight_segment_id', 'flight_deferred_items_segment_idx');
            $table->index('due_at', 'flight_deferred_items_due_idx');
            $table->index('is_warning', 'flight_deferred_items_warning_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('flight_deferred_items');
    }
}
