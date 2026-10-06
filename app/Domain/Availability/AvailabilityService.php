<?php

namespace App\Domain\Availability;

use App\Domain\Accommodation\UnitNightGuard;
use App\Domain\Pricing\PricingData;
use App\Domain\Pricing\PricingService;
use App\Models\Product;
use App\Models\Property;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The one availability engine for PMS, booking engine, channels and reports (architecture §8).
 *
 * Daily data is read with indexed range scans only: inventory_daily (ix_inv_property_date),
 * then ari_daily and ari_daily_occupancy for every product of the property (ix_ari_property_date,
 * ix_ario_property_date). Room types, products and rules are small master tables. Everything
 * else (rooms left, restrictions, derived prices, occupancy pricing) happens in memory.
 */
class AvailabilityService
{
    /** Longest stay a search accepts. */
    public const MAX_NIGHTS = 90;

    private const CHANNEL_FLAGS = ['pms' => 'sell_on_pms', 'booking_engine' => 'sell_on_booking_engine', 'channel' => 'sell_on_channels'];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly RestrictionEvaluator $restrictions,
        private readonly UnitNightGuard $guard,
    ) {}

    /**
     * Every active room type of the property with its rooms left for [checkIn, checkOut) and,
     * per active product, whether it can be sold (with reasons) and its price.
     *
     * $channel: pms | booking_engine | channel — products whose rate plan is not sold on the
     * channel are left out. $childAges: ages of children and infants when known; otherwise
     * children are priced at the lowest age of the property's "child" band and infants at 0.
     *
     * @param  list<int>|null  $childAges
     */
    public function search(Property $property, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $adults, int $children, int $infants, string $channel = 'pms', ?array $childAges = null): AvailabilityResult
    {
        $checkIn = $checkIn->startOfDay();
        $checkOut = $checkOut->startOfDay();
        $nights = (int) $checkIn->diffInDays($checkOut, false);
        if ($nights < 1) {
            throw ValidationException::withMessages(['check_out' => __('inventory.errors.date_order')]);
        }
        if ($nights > self::MAX_NIGHTS) {
            throw ValidationException::withMessages(['check_out' => __('inventory.errors.range_too_long', ['max' => self::MAX_NIGHTS])]);
        }
        $flag = self::CHANNEL_FLAGS[$channel] ?? throw new InvalidArgumentException("Unknown channel \"{$channel}\".");
        $adults = max(0, $adults);
        $children = max(0, $children);
        $infants = max(0, $infants);
        $today = CarbonImmutable::parse($this->guard->today($property));

        // Master data. The property is filtered explicitly, so these work with or without a
        // selected property (booking engine, jobs).
        $roomTypes = RoomType::acrossProperties()->where('property_id', $property->id)
            ->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        $products = Product::acrossProperties()->where('property_id', $property->id)
            ->with(['occupancyRules', 'ratePlan'])
            ->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        foreach ($products as $product) {
            $product->setRelation('roomType', $roomTypes->get($product->room_type_id));
            $product->setRelation('parent', $product->parent_product_id ? $products->get($product->parent_product_id) : null);
        }

        // Query 1: inventory for all room types and nights.
        $inventory = [];
        $rows = DB::table('inventory_daily')
            ->where('property_id', $property->id)
            ->where('stay_date', '>=', $checkIn->toDateString())
            ->where('stay_date', '<', $checkOut->toDateString())
            ->get(['room_type_id', 'total_units', 'ooo_units', 'sold', 'held', 'sell_limit', 'stop_sell']);
        foreach ($rows as $r) {
            $rt = (int) $r->room_type_id;
            $left = min((int) $r->total_units, $r->sell_limit === null ? PHP_INT_MAX : (int) $r->sell_limit)
                - (int) $r->ooo_units - (int) $r->sold - (int) $r->held;
            $inventory[$rt]['nights'] = ($inventory[$rt]['nights'] ?? 0) + 1;
            $inventory[$rt]['left'] = min($inventory[$rt]['left'] ?? PHP_INT_MAX, max(0, $left));
            $inventory[$rt]['stop_sell'] = ($inventory[$rt]['stop_sell'] ?? false) || (bool) $r->stop_sell;
        }

        // Query 2 (+ occupancy prices): rates and restrictions, check-out date included for CTD.
        $data = PricingData::load($property->id, null, $checkIn, $checkOut, (string) $property->currency_code);
        $ages = $childAges ?? $this->defaultAges($data->bands, $children, $infants);

        $result = [];
        foreach ($roomTypes as $roomType) {
            if (! $roomType->is_active || $roomType->deleted_at !== null) {
                continue;
            }
            $inv = $inventory[$roomType->id] ?? ['nights' => 0, 'left' => 0, 'stop_sell' => false];
            $complete = $inv['nights'] === $nights;
            $available = $complete ? (int) $inv['left'] : 0;
            $occupancyOk = $adults >= 1
                && $adults <= $roomType->max_adults
                && $children <= $roomType->max_children
                && $infants <= $roomType->max_infants
                && $adults + $children <= $roomType->max_occupancy;

            $roomReasons = [];
            if (! $occupancyOk) {
                $roomReasons[] = 'occupancy';
            }
            if (! $complete) {
                $roomReasons[] = 'no_inventory';
            } elseif ($available < 1) {
                $roomReasons[] = 'sold_out';
            }
            if ($inv['stop_sell']) {
                $roomReasons[] = 'stop_sell';
            }

            $entries = [];
            foreach ($products as $product) {
                $plan = $product->ratePlan;
                if ((int) $product->room_type_id !== (int) $roomType->id || ! $product->is_active || $plan === null
                    || ! $plan->is_active || $plan->deleted_at !== null || ! $plan->{$flag}) {
                    continue;
                }

                $rows = [];
                for ($d = $checkIn; $d->lessThanOrEqualTo($checkOut); $d = $d->addDay()) {
                    $rows[$d->toDateString()] = $this->restrictionRow($product, $d->toDateString(), $data, 0);
                }
                $reasons = array_merge($roomReasons, $this->restrictions->evaluate($rows, $checkIn, $checkOut, $today));

                $quote = $occupancyOk ? $this->pricing->quoteWith($product, $checkIn, $checkOut, $adults, $ages, $data) : null;
                if ($quote === null && $occupancyOk && ! in_array('no_rate', $reasons, true)) {
                    $reasons[] = 'no_rate';
                }
                $reasons = array_values(array_unique($reasons));

                $entries[] = [
                    'product_id' => (int) $product->id,
                    'product' => ['id' => $product->public_id, 'pricing_mode' => $product->pricing_mode, 'is_default' => (bool) $product->is_default],
                    'rate_plan_id' => (int) $plan->id,
                    'rate_plan' => [
                        'id' => $plan->public_id, 'code' => $plan->code, 'name' => $plan->name,
                        'meal_plan_id' => (int) $plan->meal_plan_id, 'cancellation_policy_id' => (int) $plan->cancellation_policy_id,
                        'payment_type' => $plan->payment_type,
                    ],
                    'sellable' => $reasons === [],
                    'reasons' => $reasons,
                    'nightly' => $quote ? array_map(fn ($n) => ['date' => $n['date'], 'price' => $n['price']], $quote->nights) : [],
                    'total' => $quote?->roomTotal,
                    'quote' => $quote,
                ];
            }

            $result[] = [
                'room_type_id' => (int) $roomType->id,
                'room_type' => [
                    'id' => $roomType->public_id, 'code' => $roomType->code, 'name' => $roomType->name,
                    'base_adults' => $roomType->base_adults, 'max_adults' => $roomType->max_adults,
                    'max_children' => $roomType->max_children, 'max_infants' => $roomType->max_infants,
                    'max_occupancy' => $roomType->max_occupancy,
                ],
                'available_units' => $available,
                'occupancy_ok' => $occupancyOk,
                'stop_sell' => $inv['stop_sell'],
                'products' => $entries,
            ];
        }

        return new AvailabilityResult($checkIn->toDateString(), $checkOut->toDateString(), $nights, $result);
    }

    /** Restriction row that applies to the product on $date (derived products may follow their parent). */
    private function restrictionRow(Product $product, string $date, PricingData $data, int $depth): ?array
    {
        $own = $data->ari[(int) $product->id][$date] ?? null;
        if (! $product->isDerived() || ! $product->inherit_restrictions || $product->parent === null || $depth > 6) {
            return $own;
        }

        return RestrictionEvaluator::effective($own, $this->restrictionRow($product->parent, $date, $data, $depth + 1), true);
    }

    /**
     * @param  list<array{id: int, code: string, min_age: int, max_age: int}>  $bands
     * @return list<int>
     */
    private function defaultAges(array $bands, int $children, int $infants): array
    {
        $childAge = 5;
        $infantAge = 0;
        foreach ($bands as $band) {
            if ($band['code'] === 'child') {
                $childAge = $band['min_age'];
            }
            if ($band['code'] === 'infant') {
                $infantAge = $band['min_age'];
            }
        }

        return array_merge(array_fill(0, $children, $childAge), array_fill(0, $infants, $infantAge));
    }
}
