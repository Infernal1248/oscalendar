<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flight_time_opening_balances', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('flight_minutes')->nullable();
            $table->unsignedInteger('airborne_minutes')->nullable();
            $table->unsignedInteger('night_minutes')->nullable();
            $table->date('covered_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_time_opening_balances');
    }
};
