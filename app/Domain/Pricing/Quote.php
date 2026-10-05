<?php

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;

/**
 * Price of one room for one stay, before offers and taxes.
 * Amounts are decimal strings in the property currency.
 */
final class Quote
{
    /**
     * @param  array<int, array{date: string, base_price: string, occupancy_adjust: string, price: string}>  $nights
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $roomTypeId,
        public readonly int $ratePlanId,
        public readonly CarbonImmutable $checkIn,
        public readonly CarbonImmutable $checkOut,
        public readonly int $adults,
        public readonly array $childAges,
        public readonly array $nights,
        public readonly string $roomTotal,
        public readonly string $currency,
    ) {}

    public function nightCount(): int
    {
        return count($this->nights);
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId, 'room_type_id' => $this->roomTypeId, 'rate_plan_id' => $this->ratePlanId,
            'check_in' => $this->checkIn->toDateString(), 'check_out' => $this->checkOut->toDateString(),
            'adults' => $this->adults, 'child_ages' => $this->childAges, 'nights' => $this->nights,
            'room_total' => $this->roomTotal, 'currency' => $this->currency,
        ];
    }
}
