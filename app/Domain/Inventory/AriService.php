<?php

namespace App\Domain\Inventory;

use App\Models\User;

/**
 * Writes availability, rates and restrictions (single date, range or bulk edit).
 * Every successful change increments properties.ari_version and writes ari_change_log rows.
 */
class AriService
{
    /**
     * Applies the change set in one transaction.
     *
     * - Room-type fields (stopSell, sellLimit) go to inventory_daily for change->roomTypeIds.
     * - Product fields go to ari_daily / ari_daily_occupancy for change->productIds.
     * - Derived products: price / occupancy price edits are skipped (reason derived_price);
     *   restriction edits are skipped when the product inherits restrictions
     *   (reason inherits_restrictions), except stop sell, which every product may set for itself.
     * - Ids of another property are skipped (reason not_found); past dates are skipped (reason past).
     *
     * @throws \Illuminate\Validation\ValidationException when nothing valid is targeted
     */
    public function apply(AriChangeSet $changes, ?User $by = null): AriApplyResult
    {
        throw new \LogicException('Not implemented yet.');
    }
}
