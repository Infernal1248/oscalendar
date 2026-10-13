<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->json('npd_snapshot')->nullable();
            $table->string('npd_receipt_id')->nullable();
            $table->text('npd_receipt_url')->nullable();
            $table->timestamp('npd_receipt_saved_at')->nullable();
            $table->timestamp('npd_deferred_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', fn (Blueprint $table) => $table->dropColumn([
            'npd_snapshot', 'npd_receipt_id', 'npd_receipt_url', 'npd_receipt_saved_at', 'npd_deferred_until',
        ]));
    }
};
