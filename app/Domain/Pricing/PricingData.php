<?php

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily rows needed to price (and check) stays, loaded once and used in memory.
 *
 *   ari[product_id][Y-m-d]           price (string|null) + restriction fields
 *   occupancy[product_id][Y-m-d][n]  fixed price for n adults
 *   bands                            property age bands [{id, code, min_age, max_age}]
 */
final class PricingData
{
    /**
     * @param  array<int, array<string, array<string, mixed>>>  $ari
     * @param  array<int, array<string, array<int, string>>>  $occupancy
     * @param  list<array{id: int, code: string, min_age: int, max_age: int}>  $bands
     */
    public function __construct(
        public readonly array $ari,
        public readonly array $occupancy,
        public readonly array $bands,
        public readonly string $currency,
    ) {}

    /**
     * Loads stay dates [from, to] (inclusive, so the check-out date's CTD is available).
     * $productIds = null loads every product of the property (one range scan on
     * ix_ari_property_date); otherwise the primary key (product_id, stay_date) is used.
     *
     * @param  list<int>|null  $productIds
     */
    public static function load(int $propertyId, ?array $productIds, CarbonImmutable $from, CarbonImmutable $to, ?string $currency = null): self
    {
        $range = [$from->toDateString(), $to->toDateString()];

        $ari = [];
        $rows = DB::table('ari_daily')
            ->when($productIds === null, fn ($q) => $q->where('property_id', $propertyId), fn ($q) => $q->whereIn('product_id', $productIds))
            ->whereBetween('stay_date', $range)
            ->get(['product_id', 'stay_date', 'price', 'min_los', 'max_los', 'min_los_arrival', 'cta', 'ctd', 'stop_sell', 'cutoff_days', 'max_advance_days']);
        foreach ($rows as $r) {
            $ari[(int) $r->product_id][$r->stay_date] = [
                'price' => $r->price,
                'min_los' => $r->min_los === null ? null : (int) $r->min_los,
                'max_los' => $r->max_los === null ? null : (int) $r->max_los,
                'min_los_arrival' => $r->min_los_arrival === null ? null : (int) $r->min_los_arrival,
                'cta' => (bool) $r->cta,
                'ctd' => (bool) $r->ctd,
                'stop_sell' => (bool) $r->stop_sell,
                'cutoff_days' => $r->cutoff_days === null ? null : (int) $r->cutoff_days,
                'max_advance_days' => $r->max_advance_days === null ? null : (int) $r->max_advance_days,
            ];
        }

        $occupancy = [];
        $occRows = DB::table('ari_daily_occupancy')
            ->when($productIds === null, fn ($q) => $q->where('property_id', $propertyId), fn ($q) => $q->whereIn('product_id', $productIds))
            ->whereBetween('stay_date', $range)
            ->get(['product_id', 'stay_date', 'adults', 'price']);
        foreach ($occRows as $r) {
            $occupancy[(int) $r->product_id][$r->stay_date][(int) $r->adults] = (string) $r->price;
        }

        return new self($ari, $occupancy, self::bands($propertyId), $currency ?? (string) DB::table('properties')->where('id', $propertyId)->value('currency_code'));
    }

    /** @return list<array{id: int, code: string, min_age: int, max_age: int}> */
    public static function bands(int $propertyId): array
    {
        return DB::table('property_age_bands')->where('property_id', $propertyId)->orderBy('min_age')
            ->get(['id', 'code', 'min_age', 'max_age'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'code' => (string) $b->code, 'min_age' => (int) $b->min_age, 'max_age' => (int) $b->max_age])
            ->all();
    }
}
