<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
return new class extends Migration {
    public function up(): void {
        if (Artisan::call('flight-reports:repair-duty') !== 0) {
            throw new \RuntimeException('Could not repair report duty durations.');
        }
    }
    public function down(): void {
        // Recovered source data is retained on rollback.
    }
};
