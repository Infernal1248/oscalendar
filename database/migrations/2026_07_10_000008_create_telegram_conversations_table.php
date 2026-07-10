<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTelegramConversationsTable extends Migration
{
    public function up()
    {
        Schema::create('telegram_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_account_id')->constrained('telegram_accounts')->cascadeOnDelete();
            $table->string('state', 64);
            $table->json('payload')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['telegram_account_id', 'state'], 'telegram_conversations_account_state_idx');
            $table->index('expires_at', 'telegram_conversations_expires_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('telegram_conversations');
    }
}
