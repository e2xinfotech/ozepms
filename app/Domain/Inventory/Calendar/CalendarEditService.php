<?php

namespace App\Domain\Inventory\Calendar;

use App\Domain\Inventory\AriApplyResult;
use App\Domain\Inventory\AriChangeSet;
use App\Domain\Inventory\AriCopyBuilder;
use App\Domain\Inventory\AriService;
use App\Infrastructure\Database\Tx;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a calendar edit (single cell, dragged range or bulk drawer) into AriService change sets.
 *
 * The page talks in public ids; this class resolves them inside the current property (an id of
 * another property is a field error, never a silent no-op) and expands "rate plans × room types"
 * from the bulk drawer into products.
 *
 * Stop sell on the calendar means the room type (availability row). Closing a single rate plan
 * is the separate "closed" value. When one edit targets both room types and products, the room
 * type values are applied separately so a room-type stop sell does not also close each product.
 */
final class CalendarEditService
{
    private const ROOM_TYPE_VALUES = ['stop_sell', 'sell_limit'];

    public function __construct(
        private readonly AriService $ari,
        private readonly AriCopyBuilder $copies,
    ) {}

    /**
     * @param  array<string, mixed>  $values  validated value fields (price, min_los, stop_sell …)
     * @param  list<string>  $roomTypeIds  public ids
     * @param  list<string>  $productIds  public ids
     * @param  list<string>  $ratePlanIds  public ids; expanded to the products of the chosen room types
     * @param  list<int>  $weekdays  ISO 1 = Monday … 7 = Sunday; empty = every day
     * @return array{inventory_rows: int, ari_rows: int, occupancy_rows: int, ari_version: int, skipped: list<array{reason: string, label: string, dates: int}>}
     */
    public function apply(Property $property, string $from, string $to, array $values, array $roomTypeIds, array $productIds, array $ratePlanIds = [], array $weekdays = [], ?User $by = null): array
    {
        $roomTypes = $this->ids(RoomType::query(), $roomTypeIds, 'room_type_ids');
        $products = $this->ids(Product::query(), $productIds, 'product_ids');

        if ($ratePlanIds !== []) {
            $plans = $this->ids(RatePlan::query(), $ratePlanIds, 'rate_plan_ids');
            $products = array_values(array_unique(array_merge($products, Product::query()
                ->whereIn('rate_plan_id', $plans)
                ->when($roomTypes !== [], fn ($q) => $q->whereIn('room_type_id', $roomTypes))
                ->pluck('id')->map(fn ($id) => (int) $id)->all())));
            if ($products === []) {
                throw ValidationException::withMessages(['rate_plan_ids' => __('calendar.errors.no_products')]);
            }
        }

        $roomTypeValues = array_intersect_key($values, array_flip(self::ROOM_TYPE_VALUES));
        $productValues = array_diff_key($values, array_flip(self::ROOM_TYPE_VALUES));
        $base = ['date_from' => $from, 'date_to' => $to, 'weekdays' => $weekdays];

        $sets = [];
        if ($roomTypes !== [] && $products !== [] && $this->filled($roomTypeValues) && $this->filled($productValues)) {
            $sets[] = AriChangeSet::fromArray($property->id, $base + $roomTypeValues + ['room_type_ids' => $roomTypes]);
            $sets[] = AriChangeSet::fromArray($property->id, $base + $productValues + ['product_ids' => $products]);
        } elseif ($products !== [] && ! $this->filled($productValues) && $roomTypes === []) {
            // Stop sell sent for a rate row only: it closes that product.
            $sets[] = AriChangeSet::fromArray($property->id, $base + ['closed' => $values['stop_sell'] ?? null] + array_diff_key($values, ['stop_sell' => true]) + ['product_ids' => $products]);
        } else {
            // Room types only get room-type values; product values go to products.
            $sets[] = AriChangeSet::fromArray($property->id, $base + $values + [
                'room_type_ids' => $this->filled($roomTypeValues) ? $roomTypes : [],
                'product_ids' => $this->filled($productValues) ? $products : [],
            ]);
        }

        $results = Tx::run(fn () => array_map(fn (AriChangeSet $set) => $this->ari->apply($set, $by), $sets));

        return $this->summary($results);
    }

    /**
     * "Copy values": rates and/or restrictions of a source date range onto a target date range, for the
     * chosen target rate plans (optionally only some room types). The source is the same product, or
     * the product of $sourceRatePlanId for the same room type. With $dryRun nothing is kept: the
     * changes are made inside a transaction that is rolled back, so the preview counts are exact.
     *
     * @param  array{source_from: string, source_to: string, target_from: string, target_to: string, rate_plan_ids: list<string>, room_type_ids?: list<string>, source_rate_plan_id?: ?string, copy_rates?: bool, copy_restrictions?: bool, align_weekdays?: bool}  $input  public ids
     * @return array<string, mixed>  same keys as apply() plus preview, target_products, target_dates
     */
    public function copy(Property $property, array $input, bool $dryRun, ?User $by = null): array
    {
        $roomTypes = $this->ids(RoomType::query(), $input['room_type_ids'] ?? [], 'room_type_ids');
        $plans = $this->ids(RatePlan::query(), $input['rate_plan_ids'] ?? [], 'rate_plan_ids');
        $sourcePlan = null;
        if (! empty($input['source_rate_plan_id'])) {
            $sourcePlan = RatePlan::query()->where('public_id', $input['source_rate_plan_id'])->value('id')
                ?? throw ValidationException::withMessages(['source_rate_plan_id' => __('calendar.errors.unknown_record')]);
            $sourcePlan = (int) $sourcePlan;
        }

        $targets = Product::query()->whereIn('rate_plan_id', $plans)
            ->when($roomTypes !== [], fn ($q) => $q->whereIn('room_type_id', $roomTypes))
            ->orderBy('room_type_id')->orderBy('rate_plan_id')
            ->get(['id', 'room_type_id', 'rate_plan_id']);
        if ($targets->isEmpty()) {
            throw ValidationException::withMessages(['rate_plan_ids' => __('calendar.errors.no_products')]);
        }
        $sources = $sourcePlan === null ? collect() : Product::query()->where('rate_plan_id', $sourcePlan)
            ->whereIn('room_type_id', $targets->pluck('room_type_id')->unique()->all())
            ->pluck('id', 'room_type_id');

        $pairs = [];
        $noSource = 0;
        foreach ($targets as $target) {
            $source = $sourcePlan === null ? $target->id : ($sources[$target->room_type_id] ?? null);
            if ($source === null) {
                $noSource++;

                continue;
            }
            $pairs[] = [(int) $source, (int) $target->id];
        }

        $plan = $pairs === [] ? null : $this->copies->build(
            $property->id,
            (string) $input['source_from'], (string) $input['source_to'],
            (string) $input['target_from'], (string) $input['target_to'],
            $pairs,
            (bool) ($input['copy_rates'] ?? false), (bool) ($input['copy_restrictions'] ?? false),
            (bool) ($input['align_weekdays'] ?? false),
        );

        $extraSkipped = $noSource > 0 ? [['reason' => 'no_source_product', 'dates' => 0, 'products' => $noSource]] : [];
        $results = [];
        if ($plan !== null && $plan['sets'] !== []) {
            $context = ['copy' => [
                'source' => [$input['source_from'], $input['source_to']], 'target' => [$input['target_from'], $input['target_to']],
                'source_rate_plan' => $input['source_rate_plan_id'] ?? null, 'rates' => (bool) ($input['copy_rates'] ?? false),
                'restrictions' => (bool) ($input['copy_restrictions'] ?? false), 'align_weekdays' => (bool) ($input['align_weekdays'] ?? false),
            ]];
            $run = fn () => [$this->ari->applyMany($plan['sets'], $by, 'ari.copied', $context)];
            if ($dryRun) {
                DB::beginTransaction();
                try {
                    $results = $run();
                } finally {
                    DB::rollBack();
                }
            } else {
                $results = $run();
            }
        }
        if ($plan !== null) {
            $results[] = new AriApplyResult(0, 0, 0, $plan['skipped'], 0);
        }

        $out = $this->summary($results, $extraSkipped);

        return $out + [
            'preview' => $dryRun,
            'target_products' => count($pairs),
            'target_dates' => $plan['target_dates'] ?? 0,
            'mapped_dates' => $plan['mapped_dates'] ?? 0,
        ];
    }

    /**
     * Totals of several results; skipped entries are summed per reason with a translated label.
     *
     * @param  list<AriApplyResult>  $results
     * @param  list<array{reason: string, dates: int, products?: int}>  $extra
     */
    private function summary(array $results, array $extra = []): array
    {
        $out = ['inventory_rows' => 0, 'ari_rows' => 0, 'occupancy_rows' => 0, 'ari_version' => 0, 'skipped' => []];
        $skipped = [];
        foreach ($results as $result) {
            $out['inventory_rows'] += $result->inventoryRows;
            $out['ari_rows'] += $result->ariRows;
            $out['occupancy_rows'] += $result->occupancyRows;
            $out['ari_version'] = max($out['ari_version'], $result->ariVersion);
            foreach ($result->skipped as $s) {
                $skipped[$s['reason']] = ($skipped[$s['reason']] ?? 0) + (int) ($s['dates'] ?? 0);
            }
        }
        foreach ($extra as $s) {
            $skipped[$s['reason']] = ($skipped[$s['reason']] ?? 0) + (int) ($s['products'] ?? $s['dates']);
        }
        foreach ($skipped as $reason => $dates) {
            $label = __('inventory.skipped.'.$reason);
            $out['skipped'][] = ['reason' => $reason, 'label' => is_string($label) ? $label : $reason, 'dates' => $dates];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $values */
    private function filled(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Internal ids for public ids, inside the current property (tenant scope on the model).
     *
     * @param  list<string>  $publicIds
     * @return list<int>
     */
    private function ids(\Illuminate\Database\Eloquent\Builder $query, array $publicIds, string $field): array
    {
        $publicIds = array_values(array_unique(array_filter($publicIds, 'is_string')));
        if ($publicIds === []) {
            return [];
        }
        $found = $query->whereIn('public_id', $publicIds)->pluck('id', 'public_id');
        foreach ($publicIds as $i => $id) {
            if (! isset($found[$id])) {
                throw ValidationException::withMessages(["$field.$i" => __('calendar.errors.unknown_record')]);
            }
        }

        return array_values(array_map(fn ($id) => (int) $found[$id], $publicIds));
    }
}
