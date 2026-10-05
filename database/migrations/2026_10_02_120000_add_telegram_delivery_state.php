<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roster_change_events', function (Blueprint $table) {
            $table->dateTime('telegram_retry_at')->nullable()->index();
            $table->unsignedInteger('telegram_attempts')->default(0);
            $table->dateTime('telegram_buttons_removed_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('roster_change_events', function (Blueprint $table) {
            $table->dropIndex(['telegram_retry_at']);
            $table->dropColumn(['telegram_retry_at', 'telegram_attempts', 'telegram_buttons_removed_at']);
        });
    }
};
