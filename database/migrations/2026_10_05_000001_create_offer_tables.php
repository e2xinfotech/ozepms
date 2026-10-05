<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 5 — offers & promotions.
| Table definitions are taken verbatim from database/schema/ozepms_schema_v1.sql
| (the approved Phase 0 schema), so keys, indexes and CHECK constraints match it exactly.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::statement(<<<'SQL'
CREATE TABLE offers (
  id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  public_id        CHAR(26)          NOT NULL,
  property_id      BIGINT UNSIGNED   NOT NULL,
  name             VARCHAR(120)      NOT NULL,
  code             VARCHAR(30)       NOT NULL,         -- internal code
  promo_code       VARCHAR(30)       NULL,             -- NULL = automatic offer
  discount_type    ENUM('percent','fixed_per_night','fixed_per_stay') NOT NULL,
  discount_value   DECIMAL(14,4)     NOT NULL,
  min_nights       SMALLINT UNSIGNED NULL,
  max_nights       SMALLINT UNSIGNED NULL,
  min_amount       DECIMAL(14,2)     NULL,
  booking_from     DATE              NULL,
  booking_to       DATE              NULL,
  stay_from        DATE              NULL,
  stay_to          DATE              NULL,
  weekdays         TINYINT UNSIGNED  NOT NULL DEFAULT 127, -- bitmask Mon=1 … Sun=64
  min_advance_days SMALLINT UNSIGNED NULL,             -- early bird
  max_advance_days SMALLINT UNSIGNED NULL,             -- last minute
  priority         SMALLINT          NOT NULL DEFAULT 0,
  is_stackable     TINYINT(1)        NOT NULL DEFAULT 0,
  max_redemptions  INT UNSIGNED      NULL,
  redemptions      INT UNSIGNED      NOT NULL DEFAULT 0,
  on_pms           TINYINT(1)        NOT NULL DEFAULT 1,
  on_booking_engine TINYINT(1)       NOT NULL DEFAULT 1,
  is_active        TINYINT(1)        NOT NULL DEFAULT 1,
  created_at       DATETIME          NOT NULL,
  updated_at       DATETIME          NOT NULL,
  deleted_at       DATETIME          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_offers_public (public_id),
  UNIQUE KEY uq_offers_code (property_id, code),
  UNIQUE KEY uq_offers_promo (property_id, promo_code),
  KEY ix_offers_active (property_id, is_active, stay_from, stay_to),
  CONSTRAINT fk_offer_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE offer_scopes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id     BIGINT UNSIGNED NOT NULL,
  room_type_id BIGINT UNSIGNED NULL,
  rate_plan_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_os_offer (offer_id),
  CONSTRAINT fk_os_offer FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE,
  CONSTRAINT fk_os_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id),
  CONSTRAINT fk_os_rp FOREIGN KEY (rate_plan_id) REFERENCES rate_plans (id)
) ENGINE=InnoDB
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE offer_conditions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id       BIGINT UNSIGNED NOT NULL,
  condition_type VARCHAR(40)     NOT NULL,             -- min_adults, guest_country, source ...
  operator       ENUM('eq','neq','gte','lte','in','not_in') NOT NULL,
  value          JSON            NOT NULL,
  PRIMARY KEY (id),
  KEY ix_oc_offer (offer_id),
  CONSTRAINT fk_oc_offer FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE
) ENGINE=InnoDB
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE offer_applications (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id         BIGINT UNSIGNED NOT NULL,
  offer_id            BIGINT UNSIGNED NOT NULL,
  reservation_id      BIGINT UNSIGNED NOT NULL,
  reservation_room_id BIGINT UNSIGNED NULL,
  discount_amount     DECIMAL(14,2)   NOT NULL,
  snapshot            JSON            NOT NULL,        -- offer terms at time of booking
  created_at          DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_oa_offer (offer_id, created_at),
  KEY ix_oa_res (reservation_id),
  CONSTRAINT fk_oa_offer FOREIGN KEY (offer_id) REFERENCES offers (id),
  CONSTRAINT fk_oa_res FOREIGN KEY (property_id, reservation_id) REFERENCES reservations (property_id, id),
  CONSTRAINT fk_oa_rr FOREIGN KEY (reservation_room_id) REFERENCES reservation_rooms (id)
) ENGINE=InnoDB
SQL);

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('offer_applications');
        Schema::dropIfExists('offer_conditions');
        Schema::dropIfExists('offer_scopes');
        Schema::dropIfExists('offers');
        Schema::enableForeignKeyConstraints();
    }
};
