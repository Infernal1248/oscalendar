<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncLogsTable extends Migration
{
    public function up()
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->string('level', 16)->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index('sync_run_id', 'sync_logs_run_idx');
            $table->index('level', 'sync_logs_level_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sync_logs');
    }
}
