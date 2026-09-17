<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('source', 32)->default('manual');
            $table->unsignedBigInteger('amount_kopecks');
            $table->unsignedSmallInteger('duration_days');
            $table->timestamp('paid_at');
            $table->timestamp('requested_starts_at')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignId('canceled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'canceled_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
