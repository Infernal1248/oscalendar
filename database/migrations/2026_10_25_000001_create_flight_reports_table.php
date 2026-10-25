<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('flight_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flight_segment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_key', 64);
            $table->string('flight_number');
            $table->date('flight_date');
            $table->json('data');
            $table->json('summary');
            $table->string('content_hash', 64);
            $table->text('source_url');
            $table->string('storage_path')->nullable();
            // Values are supplied by the importer. DATETIME avoids MySQL's legacy
            // implicit TIMESTAMP defaults and ON UPDATE behavior in strict mode.
            $table->dateTime('expires_at');
            $table->dateTime('last_checked_at');
            $table->unsignedBigInteger('sync_run_id');
            $table->timestamps();
            $table->unique(['user_id', 'source_key']);
            $table->index(['user_id', 'flight_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('flight_reports'); }
};
