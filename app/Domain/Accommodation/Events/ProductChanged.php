<?php

namespace App\Domain\Accommodation\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by Phase 2 when a room type ↔ rate plan mapping (product) is created, activated,
 * deactivated or its pricing mode / default price changes. Phase 3 seeds or refreshes ari_daily.
 */
final class ProductChanged
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly int $productId) {}
}
