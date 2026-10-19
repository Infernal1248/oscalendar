<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_samples', function (Blueprint $table) {
            $table->dateTime('sampled_at')->primary();
            $table->json('payload');
        });
        Schema::create('monitor_report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 30);
            $table->dateTime('slot_at');
            $table->dateTime('sent_at')->nullable();
            $table->text('body');
            $table->unsignedSmallInteger('next_chunk')->default(0);
            $table->unique(['chat_id', 'slot_at']);
        });
        Schema::create('monitor_runtime', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->dateTime('seen_at');
        });
        DB::table('monitor_runtime')->insert(['key' => 'installed_at', 'seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_runtime');
        Schema::dropIfExists('monitor_report_deliveries');
        Schema::dropIfExists('monitor_samples');
    }
};
