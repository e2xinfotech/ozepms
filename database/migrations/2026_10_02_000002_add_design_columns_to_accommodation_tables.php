<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Phase 2 additions required by the approved screen designs (see docs/02-database.md,
| "Phase 2 additions"). Columns only; no data is changed.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE room_types
  ADD COLUMN category          VARCHAR(30)      NULL AFTER name,
  ADD COLUMN extra_bed_allowed TINYINT(1)       NOT NULL DEFAULT 0 AFTER max_occupancy,
  ADD COLUMN max_extra_beds    TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER extra_bed_allowed,
  ADD KEY ix_room_types_category (property_id, category)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE physical_units
  ADD COLUMN last_cleaned_at DATETIME NULL AFTER housekeeping_status
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE rate_plans
  ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER sell_on_channels
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE tax_rules
  ADD COLUMN public_id   CHAR(26) NULL AFTER id,
  ADD COLUMN kind        ENUM('tax','service_charge','fee') NOT NULL DEFAULT 'tax' AFTER tax_type,
  ADD COLUMN apply_to    SET('room_charges','add_ons','fnb','events') NOT NULL DEFAULT 'room_charges' AFTER kind,
  ADD COLUMN description VARCHAR(500) NULL AFTER name,
  ADD COLUMN is_default_for_new_room_types TINYINT(1) NOT NULL DEFAULT 0 AFTER is_compound,
  ADD COLUMN include_in_displayed_rate     TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default_for_new_room_types,
  MODIFY COLUMN calc_type ENUM('percent','fixed_per_night','fixed_per_person_night','fixed_per_stay','fixed_per_booking') NOT NULL,
  ADD UNIQUE KEY uq_tax_rules_public (public_id),
  ADD KEY ix_tax_list (property_id, kind, is_active)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tax_rules DROP KEY ix_tax_list, DROP KEY uq_tax_rules_public, DROP COLUMN public_id, DROP COLUMN kind, DROP COLUMN apply_to, DROP COLUMN description, '
            .'DROP COLUMN is_default_for_new_room_types, DROP COLUMN include_in_displayed_rate, '
            ."MODIFY COLUMN calc_type ENUM('percent','fixed_per_night','fixed_per_person_night','fixed_per_stay') NOT NULL");
        DB::statement('ALTER TABLE rate_plans DROP COLUMN is_default');
        DB::statement('ALTER TABLE physical_units DROP COLUMN last_cleaned_at');
        DB::statement('ALTER TABLE room_types DROP KEY ix_room_types_category, DROP COLUMN category, DROP COLUMN extra_bed_allowed, DROP COLUMN max_extra_beds');
    }
};
