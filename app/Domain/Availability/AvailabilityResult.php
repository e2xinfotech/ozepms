<?php

namespace App\Domain\Availability;

/**
 * Result of AvailabilityService::search().
 *
 * roomTypes: list of
 *   [
 *     'room_type_id' => int, 'room_type' => array (public fields: id = public id, code, name, base/max occupancy),
 *     'available_units' => int (rooms left on the fullest night), 'occupancy_ok' => bool, 'stop_sell' => bool,
 *     'products' => [
 *        ['product_id' => int, 'product' => array, 'rate_plan_id' => int, 'rate_plan' => array, 'sellable' => bool,
 *         'reasons' => string[] (occupancy, no_inventory, sold_out, stop_sell, closed, cta, ctd, min_los, max_los,
 *                    min_los_arrival, cutoff, max_advance, no_rate — texts in lang inventory.reasons.*),
 *         'nightly' => [['date' => 'Y-m-d', 'price' => decimal string], ...], 'total' => ?decimal string,
 *         'quote' => ?\App\Domain\Pricing\Quote],
 *     ],
 *   ]
 */
final class AvailabilityResult
{
    /** @param  array<int, array<string, mixed>>  $roomTypes */
    public function __construct(
        public readonly string $checkIn,
        public readonly string $checkOut,
        public readonly int $nights,
        public readonly array $roomTypes,
    ) {}

    public function toArray(): array
    {
        return [
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
            'nights' => $this->nights,
            'room_types' => array_map(function ($rt) {
                $rt['products'] = array_map(fn ($p) => array_merge($p, ['quote' => $p['quote']?->toArray()]), $rt['products']);

                return $rt;
            }, $this->roomTypes),
        ];
    }
}
