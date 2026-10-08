<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per request that can change data (POST, PUT, PATCH, DELETE), whoever sends it.
        // Only field names are kept, never values, so no password, token or file ends up here.
        Schema::create('request_trail', function (Blueprint $table) {
            $table->id();
            $table->char('request_id', 26)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->string('method', 8);
            $table->string('route_name', 120)->nullable();
            $table->string('route_uri', 190);
            $table->json('route_params')->nullable();
            $table->json('field_names')->nullable();
            $table->unsignedSmallInteger('status');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['user_id', 'created_at']);
            $table->index(['impersonator_id', 'created_at']);
            $table->index(['property_id', 'created_at']);
            $table->index('request_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_trail');
    }
};
