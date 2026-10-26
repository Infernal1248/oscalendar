<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('flight_documents', fn (Blueprint $t) => $t->string('storage_disk')->default('flight_documents'));
        Schema::table('flight_reports', fn (Blueprint $t) => $t->string('storage_disk')->default('flight_reports'));
    }
    public function down(): void {
        Schema::table('flight_documents', fn (Blueprint $t) => $t->dropColumn('storage_disk'));
        Schema::table('flight_reports', fn (Blueprint $t) => $t->dropColumn('storage_disk'));
    }
};
