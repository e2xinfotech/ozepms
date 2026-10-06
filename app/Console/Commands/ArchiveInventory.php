<?php

namespace App\Console\Commands;

use App\Domain\Inventory\InventoryArchiver;
use App\Models\Property;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Removes daily inventory / rate / restriction rows of old nights (retention, see InventoryArchiver). */
class ArchiveInventory extends Command
{
    protected $signature = 'inventory:archive
        {--property= : Property code (default: every property)}
        {--days= : Keep this many past days (default: config ozepms.inventory.retention_days)}
        {--log-days= : Keep change-log rows this many days (default: config ozepms.inventory.change_log_retention_days)}
        {--dry-run : Only count the rows that would be removed}';

    protected $description = 'Remove daily inventory, rate and restriction rows of nights older than the retention period';

    public function handle(InventoryArchiver $archiver): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $logDays = $this->option('log-days') !== null ? (int) $this->option('log-days') : null;
        if (($days !== null && $days < 1) || ($logDays !== null && $logDays < 1)) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');

        $query = Property::query()->orderBy('id');
        if ($this->option('property')) {
            $query->where('code', $this->option('property'));
        }

        $rows = [];
        $totals = ['inventory_daily' => 0, 'ari_daily' => 0, 'ari_daily_occupancy' => 0, 'ari_change_log' => 0];
        $query->chunkById(100, function ($properties) use ($archiver, $days, $logDays, $dryRun, &$rows, &$totals) {
            foreach ($properties as $property) {
                $result = $archiver->archive($property, $days, $logDays, $dryRun);
                foreach ($totals as $table => $n) {
                    $totals[$table] = $n + $result[$table];
                }
                if (array_sum(array_intersect_key($result, $totals)) > 0) {
                    $rows[] = [$property->code, $result['cutoff'], $result['inventory_daily'], $result['ari_daily'], $result['ari_daily_occupancy'], $result['ari_change_log']];
                }
            }
        });

        if ($rows !== []) {
            $this->table(['Property', 'Kept from', 'inventory_daily', 'ari_daily', 'ari_daily_occupancy', 'ari_change_log'], $rows);
        }
        $this->info(($dryRun ? 'Rows that would be removed: ' : 'Rows removed: ').collect($totals)->map(fn ($n, $t) => "{$t} {$n}")->implode(' · '));
        if (! $dryRun) {
            Log::info('inventory:archive', $totals);
        }

        return self::SUCCESS;
    }
}
