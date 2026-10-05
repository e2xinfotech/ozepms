<?php

namespace App\Domain\Accommodation;

use App\Models\PhysicalUnit;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Protects PMS rooms that guests are assigned to. unit_nights belongs to the reservations
 * module; it is read here through the query builder only (no model dependency).
 */
class UnitNightGuard
{
    /** First stay date of the property's operational day ("tonight"). */
    public function today(Property $property): string
    {
        $local = now($property->timezone ?: config('app.timezone'))->toDateString();
        $business = $property->business_date?->toDateString();

        return $business !== null && $business < $local ? $business : $local;
    }

    /** @return array{count: int, first: ?string} reserved nights of the unit in [from, to) */
    public function reservedNights(PhysicalUnit $unit, string $from, ?string $to = null): array
    {
        $row = DB::table('unit_nights')
            ->where('property_id', $unit->property_id)
            ->where('unit_id', $unit->id)
            ->where('kind', 'reservation')
            ->where('stay_date', '>=', $from)
            ->when($to !== null, fn ($q) => $q->where('stay_date', '<', $to))
            ->selectRaw('COUNT(*) AS nights, MIN(stay_date) AS first_date')
            ->first();

        return ['count' => (int) ($row->nights ?? 0), 'first' => $row->first_date ?? null];
    }

    public function assertNoFutureNights(PhysicalUnit $unit, Property $property, string $field = 'is_active'): void
    {
        $nights = $this->reservedNights($unit, $this->today($property));
        if ($nights['count'] > 0) {
            throw ValidationException::withMessages([$field => __('rooms.errors.unit_has_future_nights', [
                'room' => $unit->name, 'nights' => $nights['count'], 'date' => $nights['first'],
            ])]);
        }
    }

    public function assertNoNightsBetween(PhysicalUnit $unit, string $from, string $to, string $field = 'start_date'): void
    {
        $nights = $this->reservedNights($unit, $from, $to);
        if ($nights['count'] > 0) {
            throw ValidationException::withMessages([$field => __('rooms.errors.block_has_reservations', [
                'room' => $unit->name, 'nights' => $nights['count'], 'date' => $nights['first'],
            ])]);
        }
    }
}
