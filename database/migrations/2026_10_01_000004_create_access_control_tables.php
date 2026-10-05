<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('key', 80)->unique();
            $table->enum('scope', ['platform', 'property']);
            $table->string('module', 40);
        });

        // property_id NULL = system role available to every property
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->enum('scope', ['platform', 'property'])->index();
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('color', 20)->default('slate');
            $table->boolean('is_system')->default(false);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['property_id', 'code']);
            $table->foreign('property_id')->references('id')->on('properties');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedSmallInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('platform_user_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['user_id', 'role_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('property_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->boolean('is_owner')->default(false);
            $table->enum('status', ['active', 'invited', 'disabled'])->default('active');
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['property_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('property_id')->references('id')->on('properties');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('role_id')->references('id')->on('roles');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_users');
        Schema::dropIfExists('platform_user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
