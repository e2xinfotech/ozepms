<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 2 — accommodation, rate plans, products, taxes.
| Table definitions are taken verbatim from database/schema/ozepms_schema_v1.sql
| (the approved Phase 0 schema), so keys, indexes and CHECK constraints match it exactly.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::statement(<<<'SQL'
CREATE TABLE bed_types (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(30)       NOT NULL,              -- king, queen, twin, sofa_bed
  label_key   VARCHAR(80)       NOT NULL,
  sleeps      TINYINT UNSIGNED  NOT NULL DEFAULT 2,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bed_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE content_translations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id BIGINT UNSIGNED NOT NULL,
  entity_type VARCHAR(40)     NOT NULL,                -- room_type, rate_plan, amenity
  entity_id   BIGINT UNSIGNED NOT NULL,
  locale      VARCHAR(10)     NOT NULL,
  field       VARCHAR(40)     NOT NULL,                -- name, description
  value       TEXT            NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_translation (entity_type, entity_id, locale, field),
  KEY ix_translation_property (property_id, locale),
  CONSTRAINT fk_ct_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE room_types (
  id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  public_id      CHAR(26)         NOT NULL,
  property_id    BIGINT UNSIGNED  NOT NULL,
  code           VARCHAR(20)      NOT NULL,            -- DLX
  name           VARCHAR(120)     NOT NULL,
  description    TEXT             NULL,
  base_adults    TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- occupancy included in base price
  max_adults     TINYINT UNSIGNED NOT NULL,
  max_children   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_infants    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_occupancy  TINYINT UNSIGNED NOT NULL,            -- adults + children (infants excluded unless policy says)
  size_value     DECIMAL(7,2)     NULL,
  size_unit      ENUM('sqm','sqft') NULL,
  smoking_policy ENUM('non_smoking','smoking','both') NOT NULL DEFAULT 'non_smoking',
  view_label     VARCHAR(60)      NULL,
  sort_order     SMALLINT         NOT NULL DEFAULT 0,
  is_active      TINYINT(1)       NOT NULL DEFAULT 1,
  created_at     DATETIME         NOT NULL,
  updated_at     DATETIME         NOT NULL,
  deleted_at     DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_room_types_public (public_id),
  UNIQUE KEY uq_room_types_code (property_id, code),
  UNIQUE KEY uq_room_types_tenant (property_id, id),   -- target for tenant-safe composite FKs
  KEY ix_room_types_list (property_id, is_active, sort_order),
  CONSTRAINT fk_rt_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT ck_rt_occupancy CHECK (max_occupancy >= 1 AND base_adults <= max_adults)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE room_type_beds (
  room_type_id BIGINT UNSIGNED   NOT NULL,
  bed_type_id  SMALLINT UNSIGNED NOT NULL,
  quantity     TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  PRIMARY KEY (room_type_id, bed_type_id),
  CONSTRAINT fk_rtb_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id) ON DELETE CASCADE,
  CONSTRAINT fk_rtb_bed FOREIGN KEY (bed_type_id) REFERENCES bed_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE room_type_images (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  BIGINT UNSIGNED NOT NULL,
  room_type_id BIGINT UNSIGNED NOT NULL,
  path         VARCHAR(255)    NOT NULL,
  alt_text     VARCHAR(190)    NULL,
  sort_order   SMALLINT        NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_rti_room_type (room_type_id, sort_order),
  CONSTRAINT fk_rti_rt FOREIGN KEY (property_id, room_type_id) REFERENCES room_types (property_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE physical_units (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)        NOT NULL,
  property_id         BIGINT UNSIGNED NOT NULL,
  room_type_id        BIGINT UNSIGNED NOT NULL,
  name                VARCHAR(30)     NOT NULL,        -- shown on calendar: 101
  floor               VARCHAR(10)     NULL,
  building            VARCHAR(40)     NULL,
  housekeeping_status ENUM('clean','dirty','inspected') NOT NULL DEFAULT 'clean',
  is_active           TINYINT(1)      NOT NULL DEFAULT 1,
  notes               VARCHAR(500)    NULL,
  sort_order          SMALLINT        NOT NULL DEFAULT 0,
  created_at          DATETIME        NOT NULL,
  updated_at          DATETIME        NOT NULL,
  deleted_at          DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_public (public_id),
  UNIQUE KEY uq_units_name (property_id, name),
  UNIQUE KEY uq_units_tenant (property_id, id),
  KEY ix_units_room_type (property_id, room_type_id, is_active, sort_order),
  CONSTRAINT fk_unit_rt FOREIGN KEY (property_id, room_type_id) REFERENCES room_types (property_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE unit_blocks (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  BIGINT UNSIGNED NOT NULL,
  unit_id      BIGINT UNSIGNED NOT NULL,
  block_type   ENUM('out_of_order','maintenance','owner_hold') NOT NULL,
  start_date   DATE            NOT NULL,
  end_date     DATE            NOT NULL,               -- exclusive
  reason       VARCHAR(255)    NULL,
  created_by   BIGINT UNSIGNED NULL,
  released_at  DATETIME        NULL,
  released_by  BIGINT UNSIGNED NULL,
  created_at   DATETIME        NOT NULL,
  updated_at   DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_blocks_unit (unit_id, start_date),
  KEY ix_blocks_property_dates (property_id, start_date, end_date),
  CONSTRAINT fk_ub_unit FOREIGN KEY (property_id, unit_id) REFERENCES physical_units (property_id, id),
  CONSTRAINT ck_ub_dates CHECK (end_date > start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE amenities (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id BIGINT UNSIGNED NULL,
  code        VARCHAR(40)     NOT NULL,
  name        VARCHAR(80)     NOT NULL,                -- for custom amenities
  label_key   VARCHAR(80)     NULL,                    -- for global amenities (translated)
  category    ENUM('room','bathroom','media','kitchen','property','service','other') NOT NULL DEFAULT 'room',
  icon        VARCHAR(40)     NULL,
  applies_to  SET('property','room_type','unit') NOT NULL DEFAULT 'room_type',
  ota_code    VARCHAR(20)     NULL,                    -- OpenTravel RMA / HAC code
  is_active   TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  DATETIME        NOT NULL,
  updated_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_amenities_code (property_id, code),
  KEY ix_amenities_category (category),
  CONSTRAINT fk_am_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE property_amenities (
  property_id BIGINT UNSIGNED NOT NULL,
  amenity_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (property_id, amenity_id),
  CONSTRAINT fk_pa_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_pa_amenity FOREIGN KEY (amenity_id) REFERENCES amenities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE room_type_amenities (
  room_type_id BIGINT UNSIGNED NOT NULL,
  amenity_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (room_type_id, amenity_id),
  KEY ix_rta_amenity (amenity_id),
  CONSTRAINT fk_rta_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id) ON DELETE CASCADE,
  CONSTRAINT fk_rta_amenity FOREIGN KEY (amenity_id) REFERENCES amenities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE physical_unit_amenities (
  unit_id    BIGINT UNSIGNED NOT NULL,
  amenity_id BIGINT UNSIGNED NOT NULL,
  mode       ENUM('add','remove') NOT NULL DEFAULT 'add',
  PRIMARY KEY (unit_id, amenity_id),
  CONSTRAINT fk_pua_unit FOREIGN KEY (unit_id) REFERENCES physical_units (id) ON DELETE CASCADE,
  CONSTRAINT fk_pua_amenity FOREIGN KEY (amenity_id) REFERENCES amenities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE meal_plans (
  id                 SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id        BIGINT UNSIGNED   NULL,
  code               VARCHAR(20)       NOT NULL,
  name               VARCHAR(80)       NOT NULL,
  includes_breakfast TINYINT(1)        NOT NULL DEFAULT 0,
  includes_lunch     TINYINT(1)        NOT NULL DEFAULT 0,
  includes_dinner    TINYINT(1)        NOT NULL DEFAULT 0,
  is_all_inclusive   TINYINT(1)        NOT NULL DEFAULT 0,
  ota_code           VARCHAR(10)       NULL,           -- OpenTravel MPT code
  is_active          TINYINT(1)        NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_meal_plans_code (property_id, code),
  CONSTRAINT fk_mp_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE cancellation_policies (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id   BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(30)     NOT NULL,
  name          VARCHAR(120)    NOT NULL,
  is_refundable TINYINT(1)      NOT NULL DEFAULT 1,
  description   TEXT            NULL,                  -- guest-facing text
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL,
  updated_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cp_code (property_id, code),
  UNIQUE KEY uq_cp_tenant (property_id, id),
  CONSTRAINT fk_cp_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE cancellation_policy_rules (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  policy_id            BIGINT UNSIGNED NOT NULL,
  applies_to           ENUM('cancellation','no_show') NOT NULL DEFAULT 'cancellation',
  hours_before_arrival INT UNSIGNED    NOT NULL,       -- window starts when less than this remains
  charge_type          ENUM('none','first_night','nights','percent','fixed','full') NOT NULL,
  charge_value         DECIMAL(14,4)   NULL,           -- nights count / percent / fixed amount
  PRIMARY KEY (id),
  KEY ix_cpr_policy (policy_id, applies_to, hours_before_arrival),
  CONSTRAINT fk_cpr_policy FOREIGN KEY (policy_id) REFERENCES cancellation_policies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE rate_plans (
  id                     BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  public_id              CHAR(26)          NOT NULL,
  property_id            BIGINT UNSIGNED   NOT NULL,
  code                   VARCHAR(20)       NOT NULL,   -- BAR, BB, NRF
  name                   VARCHAR(120)      NOT NULL,
  description            TEXT              NULL,
  meal_plan_id           SMALLINT UNSIGNED NOT NULL,
  cancellation_policy_id BIGINT UNSIGNED   NOT NULL,
  payment_type           ENUM('pay_at_property','prepay_full','deposit_percent','deposit_nights') NOT NULL DEFAULT 'pay_at_property',
  deposit_value          DECIMAL(14,4)     NULL,
  default_min_los        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  default_max_los        SMALLINT UNSIGNED NULL,
  min_advance_days       SMALLINT UNSIGNED NULL,     -- booking window
  max_advance_days       SMALLINT UNSIGNED NULL,
  sell_on_pms            TINYINT(1)        NOT NULL DEFAULT 1,
  sell_on_booking_engine TINYINT(1)        NOT NULL DEFAULT 1,
  sell_on_channels       TINYINT(1)        NOT NULL DEFAULT 1,
  sort_order             SMALLINT          NOT NULL DEFAULT 0,
  is_active              TINYINT(1)        NOT NULL DEFAULT 1,
  created_at             DATETIME          NOT NULL,
  updated_at             DATETIME          NOT NULL,
  deleted_at             DATETIME          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate_plans_public (public_id),
  UNIQUE KEY uq_rate_plans_code (property_id, code),
  UNIQUE KEY uq_rate_plans_tenant (property_id, id),
  KEY ix_rate_plans_list (property_id, is_active, sort_order),
  CONSTRAINT fk_rp_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_rp_meal FOREIGN KEY (meal_plan_id) REFERENCES meal_plans (id),
  CONSTRAINT fk_rp_cancel FOREIGN KEY (property_id, cancellation_policy_id) REFERENCES cancellation_policies (property_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE room_type_rate_plans (
  id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)         NOT NULL,
  property_id         BIGINT UNSIGNED  NOT NULL,
  room_type_id        BIGINT UNSIGNED  NOT NULL,
  rate_plan_id        BIGINT UNSIGNED  NOT NULL,
  pricing_mode        ENUM('manual','derived') NOT NULL DEFAULT 'manual',
  parent_product_id   BIGINT UNSIGNED  NULL,           -- required when derived
  adjust_type         ENUM('percent','fixed','fixed_per_person') NULL,
  adjust_value        DECIMAL(14,4)    NULL,           -- -10.0000 = 10% cheaper
  inherit_restrictions TINYINT(1)      NOT NULL DEFAULT 1,
  default_price       DECIMAL(14,2)    NULL,           -- seeds new ari_daily rows (manual only)
  is_default          TINYINT(1)       NOT NULL DEFAULT 0,
  sort_order          SMALLINT         NOT NULL DEFAULT 0,
  is_active           TINYINT(1)       NOT NULL DEFAULT 1,
  created_at          DATETIME         NOT NULL,
  updated_at          DATETIME         NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_public (public_id),
  UNIQUE KEY uq_products_pair (room_type_id, rate_plan_id),
  UNIQUE KEY uq_products_tenant (property_id, id),
  KEY ix_products_property (property_id, is_active, room_type_id, sort_order),
  KEY ix_products_rate_plan (rate_plan_id),
  KEY ix_products_parent (parent_product_id),
  CONSTRAINT fk_prod_rt FOREIGN KEY (property_id, room_type_id) REFERENCES room_types (property_id, id),
  CONSTRAINT fk_prod_rp FOREIGN KEY (property_id, rate_plan_id) REFERENCES rate_plans (property_id, id),
  CONSTRAINT fk_prod_parent FOREIGN KEY (property_id, parent_product_id) REFERENCES room_type_rate_plans (property_id, id),
  CONSTRAINT ck_prod_derived CHECK (
    (pricing_mode = 'manual'  AND parent_product_id IS NULL) OR
    (pricing_mode = 'derived' AND parent_product_id IS NOT NULL AND adjust_type IS NOT NULL AND adjust_value IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE product_occupancy_rules (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_id   BIGINT UNSIGNED  NOT NULL,
  guest_type   ENUM('adult','child','infant') NOT NULL,
  guest_count  TINYINT UNSIGNED NOT NULL,              -- adult: total adults; child: nth child
  age_band_id  BIGINT UNSIGNED  NULL,
  adjust_type  ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  adjust_value DECIMAL(14,4)    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_occ_rule (product_id, guest_type, guest_count, age_band_id),
  CONSTRAINT fk_occ_product FOREIGN KEY (product_id) REFERENCES room_type_rate_plans (id) ON DELETE CASCADE,
  CONSTRAINT fk_occ_band FOREIGN KEY (age_band_id) REFERENCES property_age_bands (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE tax_categories (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(30)       NOT NULL,            -- accommodation, food, service
  name          VARCHAR(80)       NOT NULL,
  default_sac_hsn VARCHAR(10)     NULL,                -- 9963 for accommodation (India)
  PRIMARY KEY (id),
  UNIQUE KEY uq_tax_categories (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE tax_rules (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  country_iso2   CHAR(2)           NOT NULL,
  property_id    BIGINT UNSIGNED   NULL,
  tax_category_id SMALLINT UNSIGNED NOT NULL,
  code           VARCHAR(30)       NOT NULL,
  name           VARCHAR(80)       NOT NULL,           -- shown on invoice
  tax_type       ENUM('gst','vat','sales','tourism','city','local','service_charge','other') NOT NULL,
  calc_type      ENUM('percent','fixed_per_night','fixed_per_person_night','fixed_per_stay') NOT NULL,
  rate           DECIMAL(9,4)      NOT NULL,           -- 18.0000 or fixed amount
  slab_basis     ENUM('none','unit_night_tariff') NOT NULL DEFAULT 'none',
  slab_min       DECIMAL(14,2)     NULL,               -- inclusive
  slab_max       DECIMAL(14,2)     NULL,               -- inclusive, NULL = no upper bound
  component_mode ENUM('single','gst_split') NOT NULL DEFAULT 'single', -- gst_split => CGST+SGST / IGST
  is_inclusive   TINYINT(1)        NOT NULL DEFAULT 0, -- price already includes this tax
  is_compound    TINYINT(1)        NOT NULL DEFAULT 0, -- applied on (price + previous taxes)
  priority       SMALLINT          NOT NULL DEFAULT 0,
  effective_from DATE              NOT NULL,
  effective_to   DATE              NULL,
  is_active      TINYINT(1)        NOT NULL DEFAULT 1,
  created_at     DATETIME          NOT NULL,
  updated_at     DATETIME          NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tax_lookup (property_id, tax_category_id, is_active, effective_from),
  KEY ix_tax_country (country_iso2, property_id),
  CONSTRAINT fk_tax_country FOREIGN KEY (country_iso2) REFERENCES countries (iso2),
  CONSTRAINT fk_tax_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_tax_category FOREIGN KEY (tax_category_id) REFERENCES tax_categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE tax_rule_scopes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tax_rule_id  BIGINT UNSIGNED NOT NULL,
  room_type_id BIGINT UNSIGNED NULL,
  rate_plan_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_trs_rule (tax_rule_id),
  CONSTRAINT fk_trs_rule FOREIGN KEY (tax_rule_id) REFERENCES tax_rules (id) ON DELETE CASCADE,
  CONSTRAINT fk_trs_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id),
  CONSTRAINT fk_trs_rp FOREIGN KEY (rate_plan_id) REFERENCES rate_plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('tax_rule_scopes');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('tax_categories');
        Schema::dropIfExists('product_occupancy_rules');
        Schema::dropIfExists('room_type_rate_plans');
        Schema::dropIfExists('rate_plans');
        Schema::dropIfExists('cancellation_policy_rules');
        Schema::dropIfExists('cancellation_policies');
        Schema::dropIfExists('meal_plans');
        Schema::dropIfExists('physical_unit_amenities');
        Schema::dropIfExists('room_type_amenities');
        Schema::dropIfExists('property_amenities');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('unit_blocks');
        Schema::dropIfExists('physical_units');
        Schema::dropIfExists('room_type_images');
        Schema::dropIfExists('room_type_beds');
        Schema::dropIfExists('room_types');
        Schema::dropIfExists('content_translations');
        Schema::dropIfExists('bed_types');
        Schema::enableForeignKeyConstraints();
    }
};
