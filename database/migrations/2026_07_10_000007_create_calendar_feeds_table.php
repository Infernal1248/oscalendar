<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCalendarFeedsTable extends Migration
{
    public function up()
    {
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 128)->unique();
            $table->string('name', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('include_crew')->default(false);
            $table->boolean('include_phones')->default(false);
            $table->boolean('include_deferred')->default(false);
            $table->dateTime('last_accessed_at')->nullable();
            $table->dateTime('last_generated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active'], 'calendar_feeds_user_active_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('calendar_feeds');
    }
}
