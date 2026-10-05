<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 3 — daily inventory, rates & restrictions.
| Table definitions are taken verbatim from database/schema/ozepms_schema_v1.sql
| (the approved Phase 0 schema), so keys, indexes and CHECK constraints match it exactly.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::statement(<<<'SQL'
CREATE TABLE inventory_daily (
  room_type_id BIGINT UNSIGNED   NOT NULL,
  stay_date    DATE              NOT NULL,
  property_id  BIGINT UNSIGNED   NOT NULL,
  total_units  SMALLINT UNSIGNED NOT NULL,
  ooo_units    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sold         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  held         SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- booking-engine holds awaiting payment
  sell_limit   SMALLINT UNSIGNED NULL,                 -- optional cap, never above total_units
  stop_sell    TINYINT(1)        NOT NULL DEFAULT 0,   -- room-type level close-out
  updated_at   DATETIME          NOT NULL,
  PRIMARY KEY (room_type_id, stay_date),
  KEY ix_inv_property_date (property_id, stay_date),
  -- last line of defence: the database refuses any write that would overbook
  CONSTRAINT ck_inv_no_overbook CHECK (sold + held + ooo_units <= total_units),
  CONSTRAINT ck_inv_sell_limit CHECK (sell_limit IS NULL OR sell_limit <= total_units)
) ENGINE=InnoDB
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE ari_daily (
  product_id   BIGINT UNSIGNED   NOT NULL,
  stay_date    DATE              NOT NULL,
  property_id  BIGINT UNSIGNED   NOT NULL,
  price        DECIMAL(14,2)     NULL,                 -- base-occupancy price; NULL on derived rows
  min_los      SMALLINT UNSIGNED NULL,
  max_los      SMALLINT UNSIGNED NULL,
  min_los_arrival SMALLINT UNSIGNED NULL,              -- MinLOS applied only on arrival date (OTA style)
  cta          TINYINT(1)        NOT NULL DEFAULT 0,   -- closed to arrival
  ctd          TINYINT(1)        NOT NULL DEFAULT 0,   -- closed to departure
  stop_sell    TINYINT(1)        NOT NULL DEFAULT 0,
  cutoff_days  SMALLINT UNSIGNED NULL,                 -- min days before arrival to book
  updated_at   DATETIME          NOT NULL,
  PRIMARY KEY (product_id, stay_date),
  KEY ix_ari_property_date (property_id, stay_date)
) ENGINE=InnoDB
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE ari_daily_occupancy (
  product_id   BIGINT UNSIGNED  NOT NULL,
  stay_date    DATE             NOT NULL,
  adults       TINYINT UNSIGNED NOT NULL,
  property_id  BIGINT UNSIGNED  NOT NULL,
  price        DECIMAL(14,2)    NOT NULL,
  updated_at   DATETIME         NOT NULL,
  PRIMARY KEY (product_id, stay_date, adults),
  KEY ix_ario_property_date (property_id, stay_date)
) ENGINE=InnoDB
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE ari_change_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  BIGINT UNSIGNED NOT NULL,
  scope        ENUM('inventory','rate','restriction') NOT NULL,
  room_type_id BIGINT UNSIGNED NULL,
  product_id   BIGINT UNSIGNED NULL,
  date_from    DATE            NOT NULL,
  date_to      DATE            NOT NULL,               -- inclusive
  weekdays     TINYINT UNSIGNED NOT NULL DEFAULT 127,  -- bitmask Mon=1 … Sun=64
  payload      JSON            NOT NULL,               -- {"price":4500,"min_los":2}
  source       ENUM('user','reservation','system','channel') NOT NULL,
  user_id      BIGINT UNSIGNED NULL,
  created_at   DATETIME(3)     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_acl_property (property_id, id),               -- channel manager reads "everything after id X"
  KEY ix_acl_product (product_id, date_from)
) ENGINE=InnoDB
SQL);

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ari_change_log');
        Schema::dropIfExists('ari_daily_occupancy');
        Schema::dropIfExists('ari_daily');
        Schema::dropIfExists('inventory_daily');
        Schema::enableForeignKeyConstraints();
    }
};
