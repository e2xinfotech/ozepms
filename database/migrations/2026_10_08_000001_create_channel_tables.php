<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 8 — channel manager.
| Table definitions are taken verbatim from database/schema/ozepms_schema_v1.sql
| (the approved Phase 0 schema), so keys, indexes and CHECK constraints match it exactly.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::statement(<<<'SQL'
CREATE TABLE channel_connections (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id   BIGINT UNSIGNED NOT NULL,
  provider      VARCHAR(30)     NOT NULL,              -- booking_com, expedia, agoda, airbnb
  external_hotel_id VARCHAR(64) NOT NULL,
  credentials   TEXT            NULL,                  -- encrypted
  status        ENUM('pending','active','paused','error','disconnected') NOT NULL DEFAULT 'pending',
  last_ari_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- high-water mark in ari_change_log
  settings      JSON            NULL,
  created_at    DATETIME        NOT NULL,
  updated_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cc (property_id, provider),
  CONSTRAINT fk_cc_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE channel_room_mappings (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id    BIGINT UNSIGNED NOT NULL,
  room_type_id     BIGINT UNSIGNED NOT NULL,
  external_room_id VARCHAR(64)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_ext (connection_id, external_room_id),
  UNIQUE KEY uq_crm_rt (connection_id, room_type_id),
  CONSTRAINT fk_crm_conn FOREIGN KEY (connection_id) REFERENCES channel_connections (id),
  CONSTRAINT fk_crm_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE channel_rate_plan_mappings (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id    BIGINT UNSIGNED NOT NULL,
  product_id       BIGINT UNSIGNED NOT NULL,           -- maps a PRODUCT, not a bare rate plan
  external_rate_id VARCHAR(64)     NOT NULL,
  markup_type      ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  markup_value     DECIMAL(14,4)   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crpm_ext (connection_id, external_rate_id),
  UNIQUE KEY uq_crpm_prod (connection_id, product_id),
  CONSTRAINT fk_crpm_conn FOREIGN KEY (connection_id) REFERENCES channel_connections (id),
  CONSTRAINT fk_crpm_prod FOREIGN KEY (product_id) REFERENCES room_type_rate_plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE channel_sync_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  direction     ENUM('outbound','inbound') NOT NULL,
  message_type  VARCHAR(40)     NOT NULL,              -- ari_update, reservation_new ...
  status        ENUM('queued','sent','success','failed','retrying') NOT NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  request_body  MEDIUMTEXT      NULL,
  response_body MEDIUMTEXT      NULL,
  error         VARCHAR(1000)   NULL,
  created_at    DATETIME        NOT NULL,
  updated_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_csl_conn (connection_id, created_at),
  KEY ix_csl_retry (status, updated_at),
  CONSTRAINT fk_csl_conn FOREIGN KEY (connection_id) REFERENCES channel_connections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE channel_reservations (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id  BIGINT UNSIGNED NOT NULL,
  external_ref   VARCHAR(64)     NOT NULL,
  reservation_id BIGINT UNSIGNED NULL,
  version        INT UNSIGNED    NOT NULL DEFAULT 1,   -- modification counter from OTA
  status         ENUM('new','modified','cancelled','failed') NOT NULL,
  payload        JSON            NOT NULL,
  created_at     DATETIME        NOT NULL,
  updated_at     DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cr_ext (connection_id, external_ref),
  KEY ix_cr_res (reservation_id),
  CONSTRAINT fk_cr_conn FOREIGN KEY (connection_id) REFERENCES channel_connections (id),
  CONSTRAINT fk_cr_res FOREIGN KEY (reservation_id) REFERENCES reservations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('channel_reservations');
        Schema::dropIfExists('channel_sync_logs');
        Schema::dropIfExists('channel_rate_plan_mappings');
        Schema::dropIfExists('channel_room_mappings');
        Schema::dropIfExists('channel_connections');
        Schema::enableForeignKeyConstraints();
    }
};
