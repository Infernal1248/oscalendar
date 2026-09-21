<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('mode', 8);
            $table->string('shop_id', 64);
            $table->string('kind', 16);
            $table->string('tier', 16);
            $table->unsignedInteger('days');
            $table->unsignedBigInteger('amount_kopecks');
            $table->string('status', 32)->default('creating');
            $table->uuid('provider_id')->nullable()->unique();
            $table->text('confirmation_url')->nullable();
            $table->json('payload');
            $table->json('periods')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignUuid('order_id')->nullable()->unique()->constrained('payment_orders')->restrictOnDelete();
            $table->boolean('grants_access')->default(true);
            $table->string('kind', 16)->default('subscription');
            $table->unsignedInteger('duration_days')->change();
            $table->unsignedBigInteger('recorded_by')->nullable()->change();
        });
        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained('payment_orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->unsignedBigInteger('destination_id');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('attempted_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unique(['order_id', 'channel', 'destination_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Платежи нельзя удалять откатом миграции. Используйте согласованную резервную копию БД и кода.');
    }
};
