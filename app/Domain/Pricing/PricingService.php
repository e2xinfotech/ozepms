<?php

namespace App\Domain\Pricing;

use App\Models\Product;
use Carbon\CarbonImmutable;

/**
 * Room price for a stay, before offers and taxes. The one place prices are calculated
 * (PMS reservations, booking engine, channel manager, calendar previews).
 */
class PricingService
{
    /**
     * Nightly breakdown and room total for one room of $product.
     * Per night: the product's ari_daily price (derived products: parent's price ± adjustment),
     * replaced by an ari_daily_occupancy price for the number of adults when set, plus the
     * product's occupancy rules (single occupancy, extra adults, children by age band).
     * Nights without a price make the quote null-priced: use quoteOrNull() to detect them.
     *
     * @param  list<int>  $childAges
     *
     * @throws \App\Domain\Pricing\Exceptions\NoRateException when a night has no price
     */
    public function quote(Product $product, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $adults, array $childAges): Quote
    {
        throw new \LogicException('Not implemented yet.');
    }
}
