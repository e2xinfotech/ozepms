<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Room type extras: floor price, booking engine visibility, booking notification e-mails,
| guest information texts (used in guest e-mails and later by automated guest messaging),
| invoice note and tourism registration (authority + number) for compliance reports.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $t) {
            $t->decimal('min_price', 12, 2)->nullable()->after('view_label');
            $t->boolean('show_on_booking_engine')->default(true)->after('min_price');
            $t->string('notification_emails', 500)->nullable()->after('show_on_booking_engine');
            $t->text('wifi_info')->nullable()->after('notification_emails');
            $t->text('checkin_info')->nullable()->after('wifi_info');
            $t->text('nearby_info')->nullable()->after('checkin_info');
            $t->text('activities_info')->nullable()->after('nearby_info');
            $t->text('invoice_note')->nullable()->after('activities_info');
            $t->string('registration_authority', 120)->nullable()->after('invoice_note');
            $t->string('registration_number', 80)->nullable()->after('registration_authority');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $t) {
            $t->dropColumn(['min_price', 'show_on_booking_engine', 'notification_emails', 'wifi_info', 'checkin_info', 'nearby_info', 'activities_info', 'invoice_note', 'registration_authority', 'registration_number']);
        });
    }
};
