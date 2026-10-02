<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flight_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flight_segment_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 10);
            $table->text('source_url');
            $table->string('storage_path')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('content_updated_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['flight_segment_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_documents');
    }
};
