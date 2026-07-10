<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddParserJobFieldsToSyncRuns extends Migration
{
    public function up()
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dateTime('claimed_at')->nullable()->after('started_at');
            $table->dateTime('lock_expires_at')->nullable()->after('claimed_at');
            $table->string('locked_by', 150)->nullable()->after('lock_expires_at');
            $table->unsignedSmallInteger('attempt')->default(0)->after('locked_by');
            $table->dateTime('heartbeat_at')->nullable()->after('attempt');

            $table->index(['status', 'lock_expires_at'], 'sync_runs_status_lock_expires_idx');
            $table->index(['locked_by', 'status'], 'sync_runs_locked_by_status_idx');
        });
    }

    public function down()
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex('sync_runs_locked_by_status_idx');
            $table->dropIndex('sync_runs_status_lock_expires_idx');
            $table->dropColumn([
                'claimed_at',
                'lock_expires_at',
                'locked_by',
                'attempt',
                'heartbeat_at',
            ]);
        });
    }
}
