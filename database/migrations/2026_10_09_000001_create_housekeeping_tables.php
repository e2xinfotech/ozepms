<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Housekeeping: the people who clean, which of them is responsible for a room, and the cleaning
| task that is opened when a guest checks out (the scheduler e-mails it to the responsible person).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_staff', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('property_id')->constrained('properties');
            $t->string('name', 120);
            $t->string('email', 190);
            $t->string('phone', 40)->nullable();
            $t->string('notes', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->softDeletes();
            $t->index(['property_id', 'is_active']);
        });

        Schema::table('physical_units', function (Blueprint $t) {
            $t->unsignedBigInteger('housekeeping_staff_id')->nullable()->after('housekeeping_status');
            $t->index('housekeeping_staff_id', 'ix_pu_hk_staff');
        });

        Schema::create('housekeeping_tasks', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('property_id')->constrained('properties');
            $t->unsignedBigInteger('unit_id');
            $t->unsignedBigInteger('staff_id')->nullable();
            $t->unsignedBigInteger('reservation_room_id')->nullable();
            $t->string('room_name', 60);
            $t->enum('status', ['pending', 'done', 'cancelled'])->default('pending');
            $t->string('note', 255)->nullable();
            $t->dateTime('notified_at')->nullable();
            $t->string('notified_to', 190)->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->index(['property_id', 'status']);
            $t->index(['unit_id', 'status']);
            $t->index(['status', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_tasks');
        Schema::table('physical_units', function (Blueprint $t) {
            $t->dropIndex('ix_pu_hk_staff');
            $t->dropColumn('housekeeping_staff_id');
        });
        Schema::dropIfExists('housekeeping_staff');
    }
};
