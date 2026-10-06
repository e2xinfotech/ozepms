<?php

namespace App\Console\Commands;

use App\Domain\Inventory\InventoryService;
use App\Models\Property;
use Illuminate\Console\Command;

/** Keeps daily inventory, rates and restrictions ready for the booking horizon of every property. */
class ExtendInventoryHorizon extends Command
{
    protected $signature = 'inventory:horizon {--property= : Property code (default: every property)} {--days= : Days ahead (default: config ozepms.inventory.horizon_days)}';

    protected $description = 'Create the daily inventory and rate rows up to the booking horizon';

    public function handle(InventoryService $inventory): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $query = Property::query()->whereIn('status', ['onboarding', 'active'])->orderBy('id');
        if ($this->option('property')) {
            $query->where('code', $this->option('property'));
        }

        $total = 0;
        $query->chunkById(100, function ($properties) use ($inventory, $days, &$total) {
            foreach ($properties as $property) {
                $total += $inventory->ensureHorizon($property, $days);
            }
        });
        $this->info("Rows created: {$total}");

        return self::SUCCESS;
    }
}
