<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->index(['status', 'finished_at'], 'sync_runs_status_finished_idx');
        });
        Schema::create('parser_nodes', function (Blueprint $table) {
            $table->string('node_id', 100)->primary();
            $table->string('source', 64);
            $table->string('version', 100);
            $table->unsignedSmallInteger('max_workers');
            $table->unsignedSmallInteger('busy_workers');
            $table->dateTime('last_seen_at')->index();
        });
        Schema::create('monitor_alerts', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->boolean('active');
            $table->dateTime('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_alerts');
        Schema::dropIfExists('parser_nodes');
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex('sync_runs_status_finished_idx');
        });
    }
};
