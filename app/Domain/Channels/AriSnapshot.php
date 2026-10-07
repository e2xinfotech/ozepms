<?php

namespace App\Domain\Channels;

use App\Domain\Availability\RestrictionEvaluator;
use App\Domain\Pricing\PricingData;
use App\Domain\Pricing\PricingService;
use App\Models\Product;
use App\Models\Property;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Current availability, prices and restrictions per night, computed with the same rules as the PMS
 * and the booking engine (inventory_daily, PricingService, RestrictionEvaluator):
 *
 *   room  availability = min(rooms, sell limit) − out of order − sold − held (never below 0)
 *         stop_sell    = room-type close-out
 *   rate  price        = one night for the room type's base adults (PricingService), + markup
 *         restrictions = the product's own, or its parent's for derived products that inherit
 */
class AriSnapshot
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @param  list<int>  $roomTypeIds
     * @return array<int, array<string, array{availability: int, stop_sell: bool}>>
     */
    public function rooms(int $propertyId, array $roomTypeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($roomTypeIds === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('inventory_daily')->where('property_id', $propertyId)->whereIn('room_type_id', $roomTypeIds)
            ->whereBetween('stay_date', [$from->toDateString(), $to->toDateString()])
            ->get(['room_type_id', 'stay_date', 'total_units', 'ooo_units', 'sold', 'held', 'sell_limit', 'stop_sell']);
        foreach ($rows as $r) {
            $left = min((int) $r->total_units, $r->sell_limit === null ? PHP_INT_MAX : (int) $r->sell_limit) - (int) $r->ooo_units - (int) $r->sold - (int) $r->held;
            $out[(int) $r->room_type_id][(string) $r->stay_date] = ['availability' => max(0, $left), 'stop_sell' => (bool) $r->stop_sell];
        }

        return $out;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array<string, array{price: ?string, min_los: ?int, max_los: ?int, cta: bool, ctd: bool, stop_sell: bool}>>
     */
    public function rates(Property $property, array $productIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($productIds === []) {
            return [];
        }
        $products = Product::acrossProperties()->whereIn('id', $productIds)->get();
        $chain = [];
        foreach ($products as $p) {
            $this->pricing->loadChain($p);
            for ($c = $p, $i = 0; $c !== null && $i < 8; $c = $c->isDerived() ? $c->parent : null, $i++) {
                $chain[(int) $c->id] = true;
            }
        }
        $data = PricingData::load((int) $property->id, array_keys($chain), $from, $to, (string) $property->currency_code);

        $out = [];
        foreach ($products as $p) {
            $adults = max(1, (int) ($p->roomType->base_adults ?? 2));
            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                $date = $d->toDateString();
                $quote = $this->pricing->quoteWith($p, $d, $d->addDay(), $adults, [], $data);
                $row = $this->restrictionRow($p, $date, $data, 0) ?? [];
                $out[(int) $p->id][$date] = [
                    'price' => $quote?->roomTotal,
                    'min_los' => isset($row['min_los']) ? (int) $row['min_los'] : null,
                    'max_los' => isset($row['max_los']) ? (int) $row['max_los'] : null,
                    'cta' => ! empty($row['cta']),
                    'ctd' => ! empty($row['ctd']),
                    // No price = the rate cannot be sold that night.
                    'stop_sell' => ! empty($row['stop_sell']) || $quote === null || ! $p->is_active,
                ];
            }
        }

        return $out;
    }

    /** Channel price with the mapping's markup (percent or fixed), rounded to the currency. */
    public static function withMarkup(?string $price, string $type, ?string $value, int $places): ?string
    {
        if ($price === null) {
            return null;
        }
        $v = (string) ($value ?? '0');

        return Money::round(match ($type) {
            'percent' => Money::adjustPercent($price, $v),
            'fixed' => Money::add($price, $v),
            default => $price,
        }, $places);
    }

    private function restrictionRow(Product $product, string $date, PricingData $data, int $depth): ?array
    {
        $own = $data->ari[(int) $product->id][$date] ?? null;
        if (! $product->isDerived() || ! $product->inherit_restrictions || $product->parent === null || $depth > 6) {
            return $own;
        }

        return RestrictionEvaluator::effective($own, $this->restrictionRow($product->parent, $date, $data, $depth + 1), true);
    }
}
