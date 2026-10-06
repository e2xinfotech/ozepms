<?php

namespace App\Domain\Accommodation;

use App\Domain\Accommodation\Events\RoomTypeUnitsChanged;
use App\Domain\Audit\AuditLogger;
use App\Domain\Rates\ProductService;
use App\Infrastructure\Database\Tx;
use App\Models\Amenity;
use App\Models\BedType;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\RoomType;
use App\Models\TaxRule;
use App\Models\TaxRuleScope;
use App\Support\PropertyContext;
use Illuminate\Validation\ValidationException;

/**
 * Room types with their beds, amenities and PMS rooms. Rate plan mapping (products)
 * is handled by App\Domain\Rates\ProductService.
 */
class RoomTypeService
{
    private const FIELDS = [
        'code', 'name', 'category', 'description', 'base_adults', 'max_adults', 'max_children', 'max_infants',
        'max_occupancy', 'extra_bed_allowed', 'max_extra_beds', 'size_value', 'size_unit', 'smoking_policy',
        'view_label', 'sort_order',
    ];

    public function __construct(
        private readonly PropertyContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimits $limits,
        private readonly PhysicalUnitService $units,
        private readonly ProductService $products,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated fields + beds[], amenities[], units[]
     */
    public function create(array $data): RoomType
    {
        return Tx::run(function () use ($data) {
            $property = $this->context->property();
            $this->limits->assertCanAddRoomTypes($property);
            $this->assertOccupancy($data);

            $roomType = new RoomType(array_intersect_key($data, array_flip(self::FIELDS)));
            $roomType->code = strtoupper((string) $roomType->code);
            // Checked again under the lock taken by the limit check: two forms saved at once with one code.
            if (RoomType::query()->withTrashed()->where('code', $roomType->code)->exists()) {
                throw ValidationException::withMessages(['code' => __('validation.unique', ['attribute' => __('rooms.fields.code')])]);
            }
            $roomType->sort_order ??= (int) RoomType::query()->max('sort_order') + 1;
            $roomType->is_active = (bool) ($data['is_active'] ?? true);
            $roomType->save();

            $this->syncBeds($roomType, $data['beds'] ?? []);
            $this->syncAmenities($roomType, $data['amenities'] ?? []);
            $this->applyDefaultTaxScopes($roomType);

            $this->audit->log('room_type.created', $roomType, ['after' => $roomType->only(self::FIELDS)]);

            // Inventory = PMS rooms: either named rows or a quantity named by the unit-name rules.
            $newUnits = array_values(array_filter($data['units'] ?? [], fn ($u) => empty($u['id'])));
            if ($newUnits !== []) {
                $this->units->addUnits($roomType, $newUnits);
            } elseif ((int) ($data['quantity'] ?? 0) > 0) {
                $this->units->bulkCreate($roomType, ['mode' => 'quantity', 'quantity' => (int) $data['quantity'], 'floor' => $data['floor'] ?? null]);
            } else {
                RoomTypeUnitsChanged::dispatch($property->id, $roomType->id);
            }

            if (array_key_exists('products', $data)) {
                $this->products->syncForRoomType($roomType, $data['products']);
            }

            return $roomType;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(RoomType $roomType, array $data): RoomType
    {
        return Tx::run(function () use ($roomType, $data) {
            $this->assertOccupancy(array_merge($roomType->only(self::FIELDS), $data));

            $roomType->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if ($roomType->isDirty('code')) {
                $roomType->code = strtoupper((string) $roomType->code);
            }
            if ($roomType->isDirty()) {
                $diff = $this->audit->diff($roomType);
                $roomType->save();
                $this->audit->log('room_type.updated', $roomType, $diff);
            }

            if (array_key_exists('beds', $data)) {
                $this->syncBeds($roomType, $data['beds'] ?? []);
            }
            if (array_key_exists('amenities', $data)) {
                $this->syncAmenities($roomType, $data['amenities'] ?? []);
            }
            if (array_key_exists('units', $data)) {
                $this->syncUnits($roomType, $data['units'] ?? []);
            }
            if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
                $this->growTo($roomType, (int) $data['quantity']);
            }
            if (array_key_exists('products', $data)) {
                $this->products->syncForRoomType($roomType, $data['products']);
            }

            return $roomType;
        });
    }

    /**
     * "Number of rooms" on the room type form: missing rooms are created with generated names.
     * Lowering the number is refused; rooms are deactivated one by one on the Rooms page, where
     * guests assigned to them are checked.
     */
    private function growTo(RoomType $roomType, int $target): void
    {
        $active = PhysicalUnit::query()->where('room_type_id', $roomType->id)->where('is_active', true)->count();
        if ($target < $active) {
            throw ValidationException::withMessages(['quantity' => __('rooms.errors.quantity_below_active', ['count' => $active])]);
        }
        if ($target > $active) {
            $this->units->bulkCreate($roomType, ['mode' => 'quantity', 'quantity' => $target - $active]);
        }
    }

    public function setActive(RoomType $roomType, bool $active): RoomType
    {
        if ($roomType->is_active === $active) {
            return $roomType;
        }

        return Tx::run(function () use ($roomType, $active) {
            $roomType->is_active = $active;
            $diff = $this->audit->diff($roomType);
            $roomType->save();
            $this->audit->log($active ? 'room_type.activated' : 'room_type.deactivated', $roomType, $diff);
            RoomTypeUnitsChanged::dispatch($roomType->property_id, $roomType->id);

            return $roomType;
        });
    }

    /** Makes one of the room type's products the default shown in lists and searches. */
    public function setDefaultProduct(RoomType $roomType, Product $product): void
    {
        if ((int) $product->room_type_id !== $roomType->id) {
            throw ValidationException::withMessages(['product_id' => __('rooms.errors.product_not_of_room_type')]);
        }
        if (! $product->is_active) {
            throw ValidationException::withMessages(['product_id' => __('rates.errors.product_inactive')]);
        }

        Tx::run(function () use ($roomType, $product) {
            Product::query()->where('room_type_id', $roomType->id)->where('id', '!=', $product->id)->update(['is_default' => false]);
            $product->is_default = true;
            $product->save();
            $this->audit->log('room_type.default_rate_plan_changed', $roomType, ['after' => ['product' => $product->public_id]]);
        });
    }

    /** @param  array<string, mixed>  $data */
    private function assertOccupancy(array $data): void
    {
        $base = (int) ($data['base_adults'] ?? 1);
        $maxAdults = (int) ($data['max_adults'] ?? 0);
        $maxChildren = (int) ($data['max_children'] ?? 0);
        $total = (int) ($data['max_occupancy'] ?? 0);

        $errors = [];
        if ($base > $maxAdults) {
            $errors['base_adults'] = __('rooms.errors.base_over_max');
        }
        if ($total < $maxAdults) {
            $errors['max_occupancy'] = __('rooms.errors.total_below_adults');
        }
        if ($maxChildren > $total) {
            $errors['max_children'] = __('rooms.errors.children_over_total');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param  list<array{bed_type: string, quantity: int}>  $beds */
    private function syncBeds(RoomType $roomType, array $beds): void
    {
        $types = BedType::query()->whereIn('code', array_column($beds, 'bed_type'))->pluck('id', 'code');
        $sync = [];
        foreach ($beds as $bed) {
            $id = $types[$bed['bed_type']] ?? null;
            if ($id === null) {
                throw ValidationException::withMessages(['beds' => __('rooms.errors.bed_type_unknown')]);
            }
            $sync[$id] = ['quantity' => max(1, (int) $bed['quantity'])];
        }
        $roomType->beds()->sync($sync);
    }

    /** @param  list<string>  $codes  amenity codes (global or the property's custom ones) */
    private function syncAmenities(RoomType $roomType, array $codes): void
    {
        $ids = Amenity::query()->visibleToProperty($roomType->property_id)
            ->whereIn('code', $codes)->where('is_active', true)->pluck('id')->all();
        if (count($ids) !== count(array_unique($codes))) {
            throw ValidationException::withMessages(['amenities' => __('rooms.errors.amenity_unknown')]);
        }
        $changes = $roomType->amenities()->sync($ids);
        if ($changes['attached'] !== [] || $changes['detached'] !== []) {
            $this->audit->log('room_type.amenities_changed', $roomType, ['after' => ['amenities' => $codes]]);
        }
    }

    /**
     * Applies the PMS rooms list from the room type form: rows with an id are edited,
     * rows without one are created. Rooms missing from the list are left untouched
     * (rooms are deactivated, never deleted).
     *
     * @param  list<array{id?: ?string, name: string, floor?: ?string, is_active?: bool}>  $rows
     */
    private function syncUnits(RoomType $roomType, array $rows): void
    {
        $existing = PhysicalUnit::query()->where('room_type_id', $roomType->id)
            ->whereIn('public_id', array_filter(array_column($rows, 'id')))
            ->get()->keyBy('public_id');

        $new = [];
        foreach ($rows as $index => $row) {
            if (empty($row['id'])) {
                $new[] = $row;

                continue;
            }
            $unit = $existing[$row['id']] ?? null;
            if (! $unit) {
                throw ValidationException::withMessages(["units.$index.id" => __('rooms.errors.unit_unknown')]);
            }
            $this->units->update($unit, array_intersect_key($row, array_flip(['name', 'floor', 'is_active'])));
        }

        $this->units->addUnits($roomType, $new);
    }

    /** Rules marked "default for new room types" that are limited to room types include the new one. */
    private function applyDefaultTaxScopes(RoomType $roomType): void
    {
        TaxRule::query()->forProperty($roomType->property_id)
            ->where('is_default_for_new_room_types', true)
            ->whereHas('scopes', fn ($q) => $q->whereNotNull('room_type_id'))
            ->pluck('id')
            ->each(fn (int $ruleId) => TaxRuleScope::query()->create(['tax_rule_id' => $ruleId, 'room_type_id' => $roomType->id]));
    }
}
