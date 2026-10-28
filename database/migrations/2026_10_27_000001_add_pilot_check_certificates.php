<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('pilot_check_syncs', function (Blueprint $table) {
            $table->json('certificates')->nullable();
            $table->dateTime('certificates_synced_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('pilot_check_syncs', fn (Blueprint $table) => $table->dropColumn(['certificates', 'certificates_synced_at']));
    }
};
