<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing connections keep working (default "approved"); new ones to real channels start as "pending".
        Schema::table('channel_connections', function (Blueprint $table) {
            $table->enum('approval_status', ['pending', 'approved', 'rejected', 'suspended'])->default('approved')->after('status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approval_status');
            $table->dateTime('approved_at')->nullable()->after('approved_by');
            $table->string('approval_note', 500)->nullable()->after('approved_at');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('channel_connections', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approved_by', 'approved_at', 'approval_note']);
        });
    }
};
