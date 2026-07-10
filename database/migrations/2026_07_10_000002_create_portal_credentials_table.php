<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePortalCredentialsTable extends Migration
{
    public function up()
    {
        Schema::create('portal_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('portal', 64)->default('rossiya_edu');
            $table->string('login', 150);
            $table->text('password_encrypted');
            $table->unsignedSmallInteger('key_version')->default(1);
            $table->string('status', 32)->default('active');
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->text('last_error_text')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'portal'], 'portal_credentials_user_portal_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('portal_credentials');
    }
}
