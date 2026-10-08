<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Extras for restaurants, bars and other outlets:
|  - two more tax buckets (beverage, liquor) with their own tax categories, so food, soft drinks and
|    alcohol can carry different GST / VAT;
|  - an outlet (department) on services and on folio lines, and a bill reference (check number),
|    so the reservation and the invoice can show each outlet's total and tax separately.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tax_rules MODIFY apply_to SET('room_charges','add_ons','fnb','events','beverage','liquor') NOT NULL DEFAULT 'room_charges'");
        foreach ([['beverage', 'Beverages', '996331'], ['alcohol', 'Alcohol & liquor', '996332']] as [$code, $name, $sac]) {
            DB::table('tax_categories')->insertOrIgnore(['code' => $code, 'name' => $name, 'default_sac_hsn' => $sac]);
        }
        Schema::table('services', function (Blueprint $t) {
            $t->string('department', 20)->default('other')->after('posting_rule');
        });
        Schema::table('folio_lines', function (Blueprint $t) {
            $t->string('department', 20)->nullable()->after('line_type');
            $t->string('reference', 40)->nullable()->after('description');
            $t->index(['folio_id', 'department'], 'ix_fl_department');
        });
        // Existing food items belong to the restaurant.
        DB::table('services')->whereIn('tax_category_id', DB::table('tax_categories')->where('code', 'food')->pluck('id'))->update(['department' => 'restaurant']);
        DB::table('folio_lines')->whereIn('service_id', DB::table('services')->where('department', 'restaurant')->pluck('id'))->update(['department' => 'restaurant']);
    }

    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $t) {
            $t->dropIndex('ix_fl_department');
            $t->dropColumn(['department', 'reference']);
        });
        Schema::table('services', function (Blueprint $t) {
            $t->dropColumn('department');
        });
        DB::statement("ALTER TABLE tax_rules MODIFY apply_to SET('room_charges','add_ons','fnb','events') NOT NULL DEFAULT 'room_charges'");
    }
};
