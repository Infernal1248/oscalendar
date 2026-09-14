<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_change_events', function (Blueprint $table) {
            $table->dropUnique('roster_change_events_identity_unique');
            $table->index(['user_id', 'source', 'period', 'change_hash'], 'roster_change_events_identity_index');
        });
    }

    public function down(): void
    {
        if (DB::table('roster_change_events')->groupBy('user_id', 'source', 'period', 'change_hash')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Нельзя восстановить уникальность повторяющихся версий без потери истории. Используйте резервную копию для отката.');
        }
        Schema::table('roster_change_events', function (Blueprint $table) {
            $table->dropIndex('roster_change_events_identity_index');
            $table->unique(['user_id', 'source', 'period', 'change_hash'], 'roster_change_events_identity_unique');
        });
    }
};
