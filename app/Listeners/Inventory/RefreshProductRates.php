<?php

namespace App\Listeners\Inventory;

use App\Domain\Accommodation\Events\ProductChanged;
use App\Domain\Inventory\AriJournal;
use App\Domain\Inventory\InventoryService;
use App\Models\Property;

/**
 * A product (room type × rate plan) was created, (de)activated or its pricing changed:
 * daily rows are created for the horizon (default price, rate plan restrictions), and the
 * change is journaled so cached availability and channels pick it up.
 */
class RefreshProductRates
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AriJournal $journal,
    ) {}

    public function handle(ProductChanged $event): void
    {
        $property = Property::query()->find($event->propertyId);
        if ($property === null) {
            return;
        }
        $from = $this->inventory->today($property->id);
        $to = $this->inventory->horizonEnd($from);

        $this->inventory->ensureProductRows($event->productId, $from, $to);
        $this->journal->record($property->id, 'rate', null, $event->productId, $from, $to->subDay(), ['product' => 'changed'], 'system', auth()->id());
        $this->journal->bump($property->id);
    }
}
