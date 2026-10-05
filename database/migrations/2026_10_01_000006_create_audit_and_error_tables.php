<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. No foreign keys so writes never block and the table can be partitioned later.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 60);
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->char('request_id', 26)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('changes')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['property_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('system_error_events', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();
            $table->enum('level', ['warning', 'error', 'critical']);
            $table->enum('source', ['server', 'client', 'queue', 'scheduler']);
            $table->string('exception_class', 190)->nullable();
            $table->string('message', 1000);
            $table->string('location', 255)->nullable();
            $table->char('last_request_id', 26)->nullable();
            $table->unsignedBigInteger('last_property_id')->nullable();
            $table->unsignedBigInteger('last_user_id')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('resolved_at')->nullable();
            $table->index(['resolved_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_error_events');
        Schema::dropIfExists('audit_logs');
    }
};
