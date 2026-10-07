<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 8 — columns the channel manager needs on top of the approved channel tables:
|   channel_connections: public id (URLs), display name, sync health (last success / error,
|     consecutive failures, next attempt for back-off, last full sync).
|   channel_sync_logs:   summary line and item count shown in the log viewer.
|   channel_reservations: error text of a failed import (shown with "Try again").
|   channel_test_listings: what the built-in Test Channel has received (its "extranet"), so the
|     sync can be checked without a real OTA.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE channel_connections
  ADD COLUMN public_id CHAR(26) NULL AFTER id,
  ADD COLUMN name VARCHAR(80) NULL AFTER provider,
  ADD COLUMN last_success_at DATETIME NULL AFTER last_ari_log_id,
  ADD COLUMN last_error_at DATETIME NULL AFTER last_success_at,
  ADD COLUMN last_error VARCHAR(1000) NULL AFTER last_error_at,
  ADD COLUMN failures SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_error,
  ADD COLUMN next_attempt_at DATETIME NULL AFTER failures,
  ADD COLUMN last_full_sync_at DATETIME NULL AFTER next_attempt_at,
  ADD UNIQUE KEY uq_cc_public (public_id),
  ADD KEY ix_cc_due (status, next_attempt_at)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE channel_sync_logs
  ADD COLUMN summary VARCHAR(255) NULL AFTER message_type,
  ADD COLUMN items INT UNSIGNED NOT NULL DEFAULT 0 AFTER summary,
  ADD KEY ix_csl_conn_type (connection_id, message_type, created_at)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE channel_reservations
  ADD COLUMN error VARCHAR(1000) NULL AFTER payload
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE channel_test_listings (
  connection_id    BIGINT UNSIGNED   NOT NULL,
  external_room_id VARCHAR(64)       NOT NULL,
  external_rate_id VARCHAR(64)       NOT NULL DEFAULT '',   -- '' = room-level row (availability)
  stay_date        DATE              NOT NULL,
  availability     SMALLINT UNSIGNED NULL,
  price            DECIMAL(14,2)     NULL,
  min_los          SMALLINT UNSIGNED NULL,
  max_los          SMALLINT UNSIGNED NULL,
  cta              TINYINT(1)        NOT NULL DEFAULT 0,
  ctd              TINYINT(1)        NOT NULL DEFAULT 0,
  stop_sell        TINYINT(1)        NOT NULL DEFAULT 0,
  updated_at       DATETIME          NOT NULL,
  PRIMARY KEY (connection_id, external_room_id, external_rate_id, stay_date),
  CONSTRAINT fk_ctl_conn FOREIGN KEY (connection_id) REFERENCES channel_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_test_listings');
        DB::statement('ALTER TABLE channel_reservations DROP COLUMN error');
        DB::statement('ALTER TABLE channel_sync_logs DROP KEY ix_csl_conn_type, DROP COLUMN summary, DROP COLUMN items');
        DB::statement('ALTER TABLE channel_connections DROP KEY uq_cc_public, DROP KEY ix_cc_due, DROP COLUMN public_id, DROP COLUMN name, DROP COLUMN last_success_at, DROP COLUMN last_error_at, DROP COLUMN last_error, DROP COLUMN failures, DROP COLUMN next_attempt_at, DROP COLUMN last_full_sync_at');
    }
};
