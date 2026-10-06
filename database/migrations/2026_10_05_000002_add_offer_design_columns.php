<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Phase 5 additions (design offers.png): offer type for the list tabs, description and one image
| for the side panel, and "free nights" discounts (stay X pay Y: min_nights = X, discount_value =
| free nights per block of X nights).
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE offers
            ADD COLUMN offer_type ENUM('room_discount','package','early_bird','last_minute','long_stay','other') NOT NULL DEFAULT 'room_discount' AFTER code,
            ADD COLUMN description TEXT NULL AFTER offer_type,
            ADD COLUMN image_path VARCHAR(255) NULL AFTER description,
            MODIFY COLUMN discount_type ENUM('percent','fixed_per_night','fixed_per_stay','free_nights') NOT NULL,
            ADD KEY ix_offers_type (property_id, offer_type)");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE offers DROP KEY ix_offers_type, DROP COLUMN image_path, DROP COLUMN description, DROP COLUMN offer_type,
            MODIFY COLUMN discount_type ENUM('percent','fixed_per_night','fixed_per_stay') NOT NULL");
    }
};
