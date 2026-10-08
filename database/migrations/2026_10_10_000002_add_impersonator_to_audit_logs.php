<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when the change was made by platform staff acting as another user ("log in as").
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('impersonator_id')->nullable()->after('user_id');
            $table->index(['impersonator_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['impersonator_id', 'created_at']);
            $table->dropColumn('impersonator_id');
        });
    }
};
