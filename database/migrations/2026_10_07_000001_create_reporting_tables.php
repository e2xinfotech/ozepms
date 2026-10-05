<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 7 — reporting rollups.
| Table definitions are taken verbatim from database/schema/ozepms_schema_v1.sql
| (the approved Phase 0 schema), so keys, indexes and CHECK constraints match it exactly.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::statement(<<<'SQL'
CREATE TABLE stats_daily (
  property_id     BIGINT UNSIGNED   NOT NULL,
  stay_date       DATE              NOT NULL,
  room_type_id    BIGINT UNSIGNED   NOT NULL,
  units_total     SMALLINT UNSIGNED NOT NULL,
  units_ooo       SMALLINT UNSIGNED NOT NULL,
  rooms_sold      SMALLINT UNSIGNED NOT NULL,
  room_revenue    DECIMAL(14,2)     NOT NULL,
  arrivals        SMALLINT UNSIGNED NOT NULL,
  departures      SMALLINT UNSIGNED NOT NULL,
  cancellations   SMALLINT UNSIGNED NOT NULL,
  no_shows        SMALLINT UNSIGNED NOT NULL,
  refreshed_at    DATETIME          NOT NULL,
  PRIMARY KEY (property_id, stay_date, room_type_id)
) ENGINE=InnoDB
SQL);

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('stats_daily');
        Schema::enableForeignKeyConstraints();
    }
};
