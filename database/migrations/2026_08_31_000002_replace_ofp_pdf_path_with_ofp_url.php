<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_segments', function (Blueprint $table) {
            $table->dropColumn('ofp_pdf_path');
            $table->text('ofp_url')->nullable()->after('download_doc_url');
        });
    }

    public function down(): void
    {
        Schema::table('flight_segments', function (Blueprint $table) {
            $table->dropColumn('ofp_url');
            $table->string('ofp_pdf_path')->nullable()->after('download_doc_url');
        });
    }
};
