<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Self-registered properties wait for approval; rejected ones stay closed.
        DB::statement("ALTER TABLE properties MODIFY status ENUM('pending_approval','rejected','onboarding','active','suspended','inactive') NOT NULL DEFAULT 'onboarding'");

        // Plans made by an Admin cannot be used until a Super Admin approves them.
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->enum('approval_status', ['approved', 'pending', 'rejected'])->default('approved')->after('is_active');
            $table->unsignedBigInteger('created_by')->nullable()->after('approval_status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('created_by');
            $table->dateTime('approved_at')->nullable()->after('approved_by');
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->enum('type', ['property_registration', 'plan', 'channel_connection']);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('summary', 255);
            $table->json('details')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('decided_at')->nullable();
            $table->index(['status', 'type', 'requested_at']);
            $table->index(['type', 'subject_id']);
            $table->index('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'created_by', 'approved_by', 'approved_at']);
        });
    }
};
