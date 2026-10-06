<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Phase 3: booking window per date. cutoff_days already holds the minimum days between
| booking and arrival; max_advance_days holds the maximum (see docs/02-database.md, "Phase 3 additions").
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ari_daily ADD COLUMN max_advance_days SMALLINT UNSIGNED NULL AFTER cutoff_days');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ari_daily DROP COLUMN max_advance_days');
    }
};
