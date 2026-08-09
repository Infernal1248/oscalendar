<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_change_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 64)->default('rossiya_edu');
            $table->string('period', 7);
            $table->string('change_hash', 64);
            $table->string('status', 32)->default('pending');
            $table->json('changes');
            $table->json('portal_state')->nullable();
            $table->json('telegram_messages')->nullable();
            $table->json('acknowledgement_messages')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('acknowledgement_requested_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('acknowledgement_notified_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source', 'period', 'change_hash'], 'roster_change_events_identity_unique');
            $table->index(['user_id', 'source', 'period', 'status'], 'roster_change_events_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_change_events');
    }
};
