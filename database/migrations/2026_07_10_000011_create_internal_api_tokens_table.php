<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInternalApiTokensTable extends Migration
{
    public function up()
    {
        Schema::create('internal_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('token_hash', 128)->unique();
            $table->json('abilities')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('internal_api_tokens');
    }
}
