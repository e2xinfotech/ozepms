<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('job_title', 80)->nullable();
            $table->string('email', 190)->unique();
            $table->string('phone_e164', 20)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('password');
            $table->boolean('is_platform_user')->default(false);
            $table->enum('status', ['active', 'invited', 'disabled', 'locked'])->default('active')->index();
            $table->string('locale', 10)->default('en');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery')->nullable();
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('password_changed_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedBigInteger('last_property_id')->nullable();
            $table->dateTime('email_verified_at')->nullable();
            $table->rememberToken();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email', 190);
            $table->string('ip', 45);
            $table->string('user_agent', 255)->nullable();
            $table->boolean('succeeded');
            $table->string('reason', 40)->nullable();
            $table->dateTime('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
