<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('portal_profiles', fn (Blueprint $t) => $t->string('position', 255)->nullable());
        Schema::table('users', fn (Blueprint $t) => $t->boolean('check_notifications_enabled')->default(true));
        Schema::create('pilot_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('origin', 16);
            $t->string('source_key', 64)->nullable();
            $t->string('title', 1000);
            $t->string('aircraft_type', 100)->nullable();
            $t->string('document_number', 255)->nullable();
            $t->date('issued_on')->nullable();
            $t->date('valid_from')->nullable();
            $t->date('due_on')->nullable();
            $t->string('portal_status', 500)->nullable();
            $t->boolean('is_current')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'source_key']);
            $t->index(['user_id', 'origin', 'is_current']);
        });
        Schema::create('pilot_check_syncs', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->timestamp('synced_at');
            $t->unsignedBigInteger('sync_run_id');
        });
        Schema::create('pilot_check_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pilot_check_id')->constrained()->cascadeOnDelete();
            $t->date('due_on');
            $t->unsignedTinyInteger('days_before');
            $t->string('channel', 16);
            $t->string('recipient', 64);
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('retry_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unique(['pilot_check_id', 'due_on', 'days_before', 'channel', 'recipient'], 'pilot_check_delivery_unique');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('pilot_check_deliveries');
        Schema::dropIfExists('pilot_check_syncs');
        Schema::dropIfExists('pilot_checks');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('check_notifications_enabled'));
        Schema::table('portal_profiles', fn (Blueprint $t) => $t->dropColumn('position'));
    }
};
