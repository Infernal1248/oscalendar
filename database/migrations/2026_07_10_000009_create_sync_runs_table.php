<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncRunsTable extends Migration
{
    public function up()
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 32)->default('scheduler');
            $table->string('status', 32)->default('running');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('segments_found')->default(0);
            $table->unsignedInteger('segments_created')->default(0);
            $table->unsignedInteger('segments_updated')->default(0);
            $table->text('error_text')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at'], 'sync_runs_user_started_idx');
            $table->index(['status', 'started_at'], 'sync_runs_status_started_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sync_runs');
    }
}
