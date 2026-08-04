<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dateTime('last_chunk_at')->nullable()->after('heartbeat_at');
            $table->string('last_chunk_kind', 32)->nullable()->after('last_chunk_at');
        });

        Schema::create('sync_run_partial_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->char('chunk_hash', 64);
            $table->string('chunk_kind', 32);
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('segments_found')->default(0);
            $table->timestamps();

            $table->unique(['sync_run_id', 'chunk_hash'], 'sync_run_partial_chunks_run_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_run_partial_chunks');

        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn(['last_chunk_at', 'last_chunk_kind']);
        });
    }
};
