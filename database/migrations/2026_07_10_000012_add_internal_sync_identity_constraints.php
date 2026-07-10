<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddInternalSyncIdentityConstraints extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('sync_runs', 'source')) {
            Schema::table('sync_runs', function (Blueprint $table) {
                $table->string('source', 64)->default('rossiya_edu')->after('user_id');
            });
        }

        if (! $this->indexExists('roster_items', 'roster_items_user_source_external_unique')) {
            Schema::table('roster_items', function (Blueprint $table) {
                $table->unique(['user_id', 'source', 'source_external_id'], 'roster_items_user_source_external_unique');
            });
        }

        if (! $this->indexExists('roster_items', 'roster_items_user_source_hash_unique')) {
            Schema::table('roster_items', function (Blueprint $table) {
                $table->unique(['user_id', 'source', 'source_hash'], 'roster_items_user_source_hash_unique');
            });
        }

        if (! $this->indexExists('flight_segments', 'flight_segments_user_source_identity_unique')) {
            Schema::table('flight_segments', function (Blueprint $table) {
                $table->unique(
                    ['user_id', 'source', 'source_para_id', 'flight_number', 'starts_at'],
                    'flight_segments_user_source_identity_unique'
                );
            });
        }

        if (! $this->indexExists('flight_segments', 'flight_segments_user_source_hash_unique')) {
            Schema::table('flight_segments', function (Blueprint $table) {
                $table->unique(['user_id', 'source', 'source_hash'], 'flight_segments_user_source_hash_unique');
            });
        }
    }

    public function down()
    {
        $this->dropIndexIfExists('flight_segments', 'flight_segments_user_source_hash_unique');
        $this->dropIndexIfExists('flight_segments', 'flight_segments_user_source_identity_unique');
        $this->dropIndexIfExists('roster_items', 'roster_items_user_source_hash_unique');
        $this->dropIndexIfExists('roster_items', 'roster_items_user_source_external_unique');
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($index) {
            $table->dropUnique($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
        }

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('{$table}')") as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }
        }

        return false;
    }
}
