<?php

namespace App\Domain\Inventory\Calendar;

use App\Domain\Inventory\AriChangeSet;
use App\Domain\Inventory\AriService;
use App\Infrastructure\Database\Tx;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
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

    public function __construct(private readonly AriService $ari) {}

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
