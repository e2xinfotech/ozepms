<?php

namespace App\Domain\Rates;

use App\Domain\Accommodation\Events\ProductChanged;
use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Support\Money;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Products: the sellable room type ↔ rate plan pairs. Products are deactivated, never deleted,
 * because reservations reference them. Every change that affects prices or sellability
 * dispatches ProductChanged so the inventory/rates module can refresh its daily rows.
 */
class ProductService
{
    private const PRICING_FIELDS = ['pricing_mode', 'default_price', 'parent_product_id', 'adjust_type', 'adjust_value'];

    public function __construct(
        private readonly PropertyContext $context,
        private readonly AuditLogger $audit,
        private readonly DerivedPricingGuard $guard,
    ) {}

    /**
     * Creates or updates the product of a room type and rate plan.
     *
     * @param  array<string, mixed>  $data  is_active, is_default, pricing_mode, default_price, parent (Product),
     *                                      adjust_type, adjust_value, inherit_restrictions, sort_order, occupancy_rules[]
     */
    public function upsert(RoomType $roomType, RatePlan $ratePlan, array $data, string $field = 'product'): Product
    {
        return Tx::run(function () use ($roomType, $ratePlan, $data, $field) {
            $product = Product::query()->where('room_type_id', $roomType->id)->where('rate_plan_id', $ratePlan->id)->first();
            $isNew = $product === null;
            $product ??= new Product([
                'room_type_id' => $roomType->id,
                'rate_plan_id' => $ratePlan->id,
                'sort_order' => (int) Product::query()->where('room_type_id', $roomType->id)->max('sort_order') + 1,
            ]);

            $this->applyPricing($product, $data, $field);

            foreach (['inherit_restrictions', 'sort_order'] as $key) {
                if (array_key_exists($key, $data)) {
                    $product->{$key} = $data[$key];
                }
            }
            if (array_key_exists('is_active', $data)) {
                $this->assertCanToggle($product, (bool) $data['is_active'], $field);
                $product->is_active = (bool) $data['is_active'];
            } elseif ($isNew) {
                $product->is_active = true;
            }

            $pricingChanged = $isNew || $product->isDirty(self::PRICING_FIELDS);
            $activeChanged = ! $isNew && $product->isDirty('is_active');
            $diff = $isNew ? ['after' => $product->getDirty()] : $this->audit->diff($product);

            if ($isNew || $product->isDirty()) {
                $product->save();
                $this->audit->log($isNew ? 'product.created' : 'product.updated', $product, $diff);
            }

            if (array_key_exists('occupancy_rules', $data)) {
                $this->syncOccupancyRules($product, $roomType, $data['occupancy_rules'] ?? [], $field);
            }
            if (! empty($data['is_default']) && $product->is_active) {
                $this->makeDefault($product);
            }

            if ($pricingChanged || $activeChanged) {
                $this->dispatchChanged($product, $pricingChanged);
            }

            return $product;
        });
    }

    public function setActive(Product $product, bool $active): Product
    {
        if ($product->is_active === $active) {
            return $product;
        }

        return Tx::run(function () use ($product, $active) {
            $this->assertCanToggle($product, $active, 'is_active');
            $product->is_active = $active;
            if (! $active) {
                $product->is_default = false;
            }
            $diff = $this->audit->diff($product);
            $product->save();
            $this->audit->log($active ? 'product.activated' : 'product.deactivated', $product, $diff);
            $this->dispatchChanged($product, false);

            return $product;
        });
    }

    /**
     * Applies the "Rate Plans" step of the room type form. Each entry names a rate plan;
     * derived entries name their parent by rate plan (+ room type, default: this one) so
     * a parent created in the same request can be used.
     *
     * @param  list<array<string, mixed>>  $entries  rate_plan (RatePlan), enabled, parent_rate_plan (?RatePlan),
     *                                               parent_room_type (?RoomType) + upsert() fields
     */
    public function syncForRoomType(RoomType $roomType, array $entries): void
    {
        Tx::run(function () use ($roomType, $entries) {
            $pending = [];
            foreach ($entries as $index => $entry) {
                if (empty($entry['enabled'])) {
                    $existing = Product::query()->where('room_type_id', $roomType->id)->where('rate_plan_id', $entry['rate_plan']->id)->first();
                    if ($existing && $existing->is_active) {
                        $this->setActive($existing, false);
                    }

                    continue;
                }
                $pending[$index] = $entry;
            }

            // Manual products first, then derived ones whose parent already exists.
            while ($pending !== []) {
                $progress = false;
                foreach ($pending as $index => $entry) {
                    $data = $entry;
                    if (($entry['pricing_mode'] ?? 'manual') === 'derived') {
                        $parent = $this->findParent($roomType, $entry);
                        if ($parent === null) {
                            continue;
                        }
                        $data['parent'] = $parent;
                    }
                    $data['is_active'] = true;
                    $this->upsert($roomType, $entry['rate_plan'], $data, "products.$index");
                    unset($pending[$index]);
                    $progress = true;
                }

                if (! $progress) {
                    $index = array_key_first($pending);
                    throw ValidationException::withMessages(["products.$index.parent_rate_plan_id" => __('rates.errors.parent_missing')]);
                }
            }

            if (! Product::query()->where('room_type_id', $roomType->id)->where('is_active', true)->where('is_default', true)->exists()) {
                $first = Product::query()->where('room_type_id', $roomType->id)->where('is_active', true)->orderBy('sort_order')->first();
                if ($first) {
                    $this->makeDefault($first);
                }
            }
        });
    }

    /**
     * Applies the room type matrix of the rate plan form (manual prices only).
     *
     * @param  list<array{room_type: RoomType, enabled: bool, default_price?: ?string}>  $entries
     */
    public function syncForRatePlan(RatePlan $ratePlan, array $entries): void
    {
        Tx::run(function () use ($ratePlan, $entries) {
            foreach ($entries as $index => $entry) {
                $existing = Product::query()->where('room_type_id', $entry['room_type']->id)->where('rate_plan_id', $ratePlan->id)->first();

                if (! $entry['enabled']) {
                    if ($existing && $existing->is_active) {
                        $this->setActive($existing, false);
                    }

                    continue;
                }

                $data = ['is_active' => true];
                if (! $existing || ! $existing->isDerived()) {
                    $data += ['pricing_mode' => 'manual', 'default_price' => $entry['default_price'] ?? null];
                }
                $this->upsert($entry['room_type'], $ratePlan, $data, "room_types.$index");
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    private function applyPricing(Product $product, array $data, string $field): void
    {
        if (! array_key_exists('pricing_mode', $data)) {
            if (array_key_exists('default_price', $data) && ! $product->isDerived()) {
                $product->default_price = $this->price($data['default_price'], "$field.default_price");
            }

            return;
        }

        if ($data['pricing_mode'] === 'manual') {
            $product->fill([
                'pricing_mode' => 'manual',
                'parent_product_id' => null,
                'adjust_type' => null,
                'adjust_value' => null,
                'default_price' => $this->price($data['default_price'] ?? null, "$field.default_price"),
            ]);

            return;
        }

        $parent = $data['parent'] ?? null;
        if (! $parent instanceof Product) {
            throw ValidationException::withMessages(["$field.parent_rate_plan_id" => __('rates.errors.parent_missing')]);
        }
        $this->guard->assertValidParent($product->id, $parent, $this->context->id(), "$field.parent_rate_plan_id");

        $type = $data['adjust_type'] ?? null;
        $value = $data['adjust_value'] ?? null;
        if (! in_array($type, Product::ADJUST_TYPES, true) || ! Money::isDecimal((string) $value)) {
            throw ValidationException::withMessages(["$field.adjust_value" => __('rates.errors.adjust_required')]);
        }
        if ($type === 'percent' && Money::compare((string) $value, '-100') <= 0) {
            throw ValidationException::withMessages(["$field.adjust_value" => __('rates.errors.adjust_percent_range')]);
        }

        $product->fill([
            'pricing_mode' => 'derived',
            'parent_product_id' => $parent->id,
            'adjust_type' => $type,
            'adjust_value' => Money::round((string) $value, 4),
            'default_price' => null,
        ]);
    }

    private function price(mixed $value, string $field): string
    {
        if ($value === null || $value === '' || ! Money::isDecimal((string) $value) || Money::isNegative((string) $value)) {
            throw ValidationException::withMessages([$field => __('rates.errors.price_required')]);
        }

        return Money::round((string) $value, 2);
    }

    /** A parent cannot be switched off while derived products still follow it. */
    private function assertCanToggle(Product $product, bool $active, string $field): void
    {
        if ($active || ! $product->exists) {
            if ($active && $product->isDerived() && $product->parent_product_id !== null) {
                $parent = Product::query()->find($product->parent_product_id);
                if ($parent && ! $parent->is_active) {
                    throw ValidationException::withMessages([$field => __('rates.errors.parent_inactive')]);
                }
            }

            return;
        }

        $children = Product::query()->where('parent_product_id', $product->id)->where('is_active', true)->count();
        if ($children > 0) {
            throw ValidationException::withMessages([$field => __('rates.errors.has_active_children', ['count' => $children])]);
        }
    }

    /** @param  array<string, mixed>  $entry */
    private function findParent(RoomType $roomType, array $entry): ?Product
    {
        $parentPlan = $entry['parent_rate_plan'] ?? null;
        if (! $parentPlan instanceof RatePlan) {
            throw ValidationException::withMessages(['products' => __('rates.errors.parent_missing')]);
        }
        $parentRoomType = $entry['parent_room_type'] ?? $roomType;

        return Product::query()
            ->where('room_type_id', $parentRoomType->id)
            ->where('rate_plan_id', $parentPlan->id)
            ->where('is_active', true)
            ->first();
    }

    private function makeDefault(Product $product): void
    {
        Product::query()->where('room_type_id', $product->room_type_id)->where('id', '!=', $product->id)->update(['is_default' => false]);
        if (! $product->is_default) {
            $product->is_default = true;
            $product->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rules  guest_type, guest_count, age_band (code|null), adjust_type, adjust_value
     */
    private function syncOccupancyRules(Product $product, RoomType $roomType, array $rules, string $field): void
    {
        $bands = DB::table('property_age_bands')->where('property_id', $product->property_id)->pluck('id', 'code');

        $rows = [];
        $keys = [];
        foreach ($rules as $i => $rule) {
            $type = $rule['guest_type'];
            $count = (int) $rule['guest_count'];
            $bandId = null;
            if (! empty($rule['age_band'])) {
                $bandId = $bands[$rule['age_band']] ?? null;
                if ($bandId === null) {
                    throw ValidationException::withMessages(["$field.occupancy_rules.$i.age_band" => __('rates.errors.age_band_unknown')]);
                }
            }
            $limit = match ($type) {
                'adult' => $roomType->max_adults,
                'child' => $roomType->max_children,
                default => $roomType->max_infants,
            };
            if ($count < 1 || $count > max(1, $limit)) {
                throw ValidationException::withMessages(["$field.occupancy_rules.$i.guest_count" => __('rates.errors.occupancy_count', ['max' => $limit])]);
            }
            $key = "$type:$count:".($bandId ?? '-');
            if (isset($keys[$key])) {
                throw ValidationException::withMessages(["$field.occupancy_rules.$i.guest_count" => __('rates.errors.occupancy_duplicate')]);
            }
            $keys[$key] = true;

            $rows[] = [
                'guest_type' => $type,
                'guest_count' => $count,
                'age_band_id' => $bandId,
                'adjust_type' => $rule['adjust_type'] ?? 'fixed',
                'adjust_value' => Money::round((string) $rule['adjust_value'], 4),
            ];
        }

        $before = $product->occupancyRules()->get(['guest_type', 'guest_count', 'age_band_id', 'adjust_type', 'adjust_value'])->toArray();
        $product->occupancyRules()->delete();
        $product->occupancyRules()->createMany($rows);
        if ($before !== $rows) {
            $this->audit->log('product.occupancy_changed', $product, ['before' => $before, 'after' => $rows]);
            ProductChanged::dispatch((int) $product->property_id, $product->id);
        }
    }

    /** Derived products follow their parent, so their daily prices change too. */
    private function dispatchChanged(Product $product, bool $includeDescendants): void
    {
        ProductChanged::dispatch((int) $product->property_id, $product->id);
        if (! $includeDescendants) {
            return;
        }

        $seen = [$product->id => true];
        $queue = [$product->id];
        while ($queue !== []) {
            $children = Product::query()->whereIn('parent_product_id', $queue)->pluck('id')->all();
            $queue = [];
            foreach ($children as $childId) {
                if (! isset($seen[$childId])) {
                    $seen[$childId] = true;
                    $queue[] = $childId;
                    ProductChanged::dispatch((int) $product->property_id, $childId);
                }
            }
        }
    }
}
