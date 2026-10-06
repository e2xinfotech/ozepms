<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 7 — reporting rollup by rate plan and booking source (stay nights). Complements
| stats_daily (by room type, approved schema). Rebuilt per property and date range by
| App\Domain\Reports\ReportRollupService; report pages never aggregate the live booking tables.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE stats_daily_mix (
  property_id   BIGINT UNSIGNED   NOT NULL,
  stay_date     DATE              NOT NULL,
  room_type_id  BIGINT UNSIGNED   NOT NULL,
  rate_plan_id  BIGINT UNSIGNED   NOT NULL,
  source_id     SMALLINT UNSIGNED NOT NULL,
  rooms_sold    SMALLINT UNSIGNED NOT NULL,
  guests        SMALLINT UNSIGNED NOT NULL,
  room_revenue  DECIMAL(14,2)     NOT NULL,   -- net of discounts, before tax
  discount      DECIMAL(14,2)     NOT NULL,
  tax           DECIMAL(14,2)     NOT NULL,
  refreshed_at  DATETIME          NOT NULL,
  PRIMARY KEY (property_id, stay_date, room_type_id, rate_plan_id, source_id),
  KEY ix_sdm_rate_plan (property_id, rate_plan_id, stay_date),
  KEY ix_sdm_source (property_id, source_id, stay_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_daily_mix');
    }
};
