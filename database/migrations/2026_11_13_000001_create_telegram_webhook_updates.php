<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_webhook_updates', function (Blueprint $table) {
            $table->char('bot_key', 64);
            $table->unsignedBigInteger('update_id');
            $table->timestamp('created_at');
            $table->timestamp('processed_at')->nullable();
            $table->primary(['bot_key', 'update_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_webhook_updates');
    }
};
