<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['airfase', 'rrj_express_events', 'green_zone_flights'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('demo_user_id')->nullable()->constrained('users')->cascadeOnDelete());
        }
    }

    public function down(): void
    {
        foreach (['airfase', 'rrj_express_events', 'green_zone_flights'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('demo_user_id'));
        }
    }
};
