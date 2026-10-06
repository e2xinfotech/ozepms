<?php

namespace App\Domain\Accommodation;

use App\Models\PhysicalUnit;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the subscription plan's max_room_types / max_units when inventory is added.
 * Callers run inside a transaction; the property row is locked first so two requests adding
 * rooms at the same moment are checked one after the other and cannot pass the limit together.
 */
class PlanLimits
{
    /** Serialises inventory changes of one property until the surrounding transaction ends. */
    public function lockInventory(Property $property): void
    {
        Property::query()->whereKey($property->id)->lockForUpdate()->value('id');
    }

    public function plan(Property $property): ?SubscriptionPlan
    {
        $subscription = Subscription::query()
            ->where('property_id', $property->id)
            ->whereNot('status', 'cancelled')
            ->orderByDesc('ends_on')
            ->first();

        return $subscription ? SubscriptionPlan::query()->find($subscription->plan_id) : null;
    }

    /** @return array{room_types: ?int, units: ?int, used_room_types: int, used_units: int} */
    public function usage(Property $property): array
    {
        $plan = $this->plan($property);

        return [
            'room_types' => $plan?->max_room_types,
            'units' => $plan?->max_units,
            'used_room_types' => RoomType::query()->where('property_id', $property->id)->count(),
            'used_units' => PhysicalUnit::query()->where('property_id', $property->id)->where('is_active', true)->count(),
        ];
    }

    public function assertCanAddRoomTypes(Property $property, int $adding = 1, string $field = 'name'): void
    {
        $this->lockInventory($property);
        $usage = $this->usage($property);
        if ($usage['room_types'] !== null && $usage['used_room_types'] + $adding > $usage['room_types']) {
            throw ValidationException::withMessages([$field => __('rooms.errors.room_type_limit', [
                'max' => $usage['room_types'], 'used' => $usage['used_room_types'],
            ])]);
        }
    }

    public function assertCanAddUnits(Property $property, int $adding, string $field = 'units'): void
    {
        $this->lockInventory($property);
        $usage = $this->usage($property);
        if ($adding > 0 && $usage['units'] !== null && $usage['used_units'] + $adding > $usage['units']) {
            throw ValidationException::withMessages([$field => __('rooms.errors.unit_limit', [
                'max' => $usage['units'], 'used' => $usage['used_units'], 'adding' => $adding,
            ])]);
        }
    }
}
