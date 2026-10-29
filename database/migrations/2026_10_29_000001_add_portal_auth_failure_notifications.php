<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('portal_credentials', function (Blueprint $table) {
            $table->dateTime('auth_credentials_changed_at')->nullable();
            $table->dateTime('auth_failure_started_at')->nullable()->index();
            $table->dateTime('auth_failure_last_at')->nullable();
            $table->unsignedInteger('auth_failure_count')->default(0);
            $table->dateTime('auth_notified_at')->nullable();
            $table->dateTime('auth_notification_retry_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portal_credentials', function (Blueprint $table) {
            $table->dropIndex(['auth_failure_started_at']);
            $table->dropColumn(['auth_credentials_changed_at', 'auth_failure_started_at', 'auth_failure_last_at',
                'auth_failure_count', 'auth_notified_at', 'auth_notification_retry_at']);
        });
    }
};
