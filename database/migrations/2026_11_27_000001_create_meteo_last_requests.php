<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meteo_last_requests', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('airports', 400);
            $table->json('results');
            $table->dateTime('received_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meteo_last_requests');
    }
};
