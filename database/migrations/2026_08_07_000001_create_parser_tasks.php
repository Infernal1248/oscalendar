<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 191)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('roster_item_id')->nullable()->constrained('roster_items')->cascadeOnDelete();
            $table->string('source', 64)->default('rossiya_edu');
            $table->string('portal', 64)->default('rossiya_edu');
            $table->string('task_type', 32);
            $table->string('status', 32)->default('scheduled');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->dateTime('next_run_at')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('lock_expires_at')->nullable();
            $table->string('locked_by', 150)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('refresh_requested')->default(false);
            $table->dateTime('last_started_at')->nullable();
            $table->dateTime('last_finished_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->text('last_error_text')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_run_at', 'priority'], 'parser_tasks_due_idx');
            $table->index(['user_id', 'status'], 'parser_tasks_user_status_idx');
            $table->index(['task_type', 'roster_item_id'], 'parser_tasks_type_roster_idx');
        });

        Schema::table('sync_runs', function (Blueprint $table) {
            $table->foreignId('parser_task_id')->nullable()->after('user_id')->constrained('parser_tasks')->nullOnDelete();
            $table->foreignId('roster_item_id')->nullable()->after('parser_task_id')->constrained('roster_items')->nullOnDelete();
            $table->string('task_type', 32)->nullable()->after('source');
            $table->json('task_payload')->nullable()->after('task_type');
            $table->string('worker_id', 150)->nullable()->after('locked_by');
            $table->unsignedBigInteger('duration_ms')->nullable()->after('finished_at');

            $table->index(['parser_task_id', 'status'], 'sync_runs_task_status_idx');
            $table->index(['user_id', 'status', 'lock_expires_at'], 'sync_runs_user_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex('sync_runs_user_active_idx');
            $table->dropIndex('sync_runs_task_status_idx');
            $table->dropConstrainedForeignId('roster_item_id');
            $table->dropConstrainedForeignId('parser_task_id');
            $table->dropColumn(['task_type', 'task_payload', 'worker_id', 'duration_ms']);
        });

        Schema::dropIfExists('parser_tasks');
    }
};
