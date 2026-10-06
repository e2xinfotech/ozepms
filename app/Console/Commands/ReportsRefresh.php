<?php

namespace App\Console\Commands;

use App\Domain\Reports\ReportRollupService;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Rebuilds the report rollups (stats_daily, stats_daily_mix). Nightly: the last week and the whole
 * selling horizon of every property (picks up room changes and repairs anything missed).
 */
class ReportsRefresh extends Command
{
    protected $signature = 'reports:refresh
        {--property= : Property code (default: every property)}
        {--from= : First stay date (default: 7 days ago)}
        {--to= : Last stay date (default: end of the selling horizon)}
        {--all : Every date with inventory or bookings}';

    protected $description = 'Rebuild the reporting rollups from bookings and inventory';

    public function handle(ReportRollupService $rollup): int
    {
        $properties = Property::query()->whereNull('deleted_at')
            ->when($this->option('property'), fn ($q, $code) => $q->where('code', strtoupper((string) $code)))
            ->get(['id', 'code']);
        if ($properties->isEmpty()) {
            $this->error('No property found.');

            return self::FAILURE;
        }
        $from = CarbonImmutable::parse((string) ($this->option('from') ?: now()->subDays(7)->toDateString()));
        $to = CarbonImmutable::parse((string) ($this->option('to') ?: now()->addDays((int) config('ozepms.inventory.horizon_days', 730))->toDateString()))->addDay();

        foreach ($properties as $property) {
            $this->option('all') ? $rollup->refreshAll($property->id) : $rollup->refresh($property->id, $from, $to);
            $this->line("{$property->code}: refreshed");
        }

        return self::SUCCESS;
    }
}
