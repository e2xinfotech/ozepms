<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Everyone staying in a room, not only the booker: adults, children and infants with name, age,
| nationality, ID details and ID documents, kept for later checks and for the local police register.
| Companions are guest profiles marked is_companion (hidden from the guest list, never merged by e-mail).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $t) {
            $t->boolean('is_companion')->default(false)->after('is_vip');
            $t->string('gender', 10)->nullable()->after('date_of_birth');
            $t->index(['property_id', 'is_companion'], 'ix_guests_companion');
        });
        Schema::table('reservation_guests', function (Blueprint $t) {
            $t->enum('person_type', ['adult', 'child', 'infant'])->default('adult')->after('is_primary');
            $t->unsignedTinyInteger('age')->nullable()->after('person_type');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_guests', function (Blueprint $t) {
            $t->dropColumn(['person_type', 'age']);
        });
        Schema::table('guests', function (Blueprint $t) {
            $t->dropIndex('ix_guests_companion');
            $t->dropColumn(['is_companion', 'gender']);
        });
    }
};
