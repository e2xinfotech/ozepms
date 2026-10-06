<?php

namespace Tests\Feature\Calendar;

use App\Models\PhysicalUnit;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Accommodation\AccommodationTestCase;

/**
 * Calendar fixtures: products and daily rows are written directly so the tests do not depend
 * on how the engine seeds its horizon (upserts, so rows the engine already created are fine).
 */
abstract class CalendarTestCase extends AccommodationTestCase
{
    /** A product (room type × rate plan). Derived when $parentId is given. */
    protected function product(RoomType $roomType, RatePlan $plan, ?string $price = '1000.00', ?int $parentId = null, string $adjustType = 'percent', string $adjust = '-10', bool $inherit = true): int
    {
        $existing = DB::table('room_type_rate_plans')->where('room_type_id', $roomType->id)->where('rate_plan_id', $plan->id)->value('id');
        $row = [
            'pricing_mode' => $parentId ? 'derived' : 'manual',
            'parent_product_id' => $parentId,
            'adjust_type' => $parentId ? $adjustType : null,
            'adjust_value' => $parentId ? $adjust : null,
            'inherit_restrictions' => $inherit ? 1 : 0,
            'default_price' => $parentId ? null : $price,
            'is_active' => 1,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('room_type_rate_plans')->where('id', $existing)->update($row);

            return (int) $existing;
        }

        return (int) DB::table('room_type_rate_plans')->insertGetId($row + [
            'public_id' => (string) Str::ulid(), 'property_id' => $roomType->property_id, 'room_type_id' => $roomType->id,
            'rate_plan_id' => $plan->id, 'created_at' => now(),
        ]);
    }

    protected function ratePlan(string $code, ?Property $property = null): RatePlan
    {
        $property ??= $this->property;
        $bar = $this->bar($property);
        $id = DB::table('rate_plans')->where('property_id', $property->id)->where('code', $code)->value('id');
        if (! $id) {
            $id = DB::table('rate_plans')->insertGetId([
                'public_id' => (string) Str::ulid(), 'property_id' => $property->id, 'code' => $code, 'name' => 'Plan '.$code,
                'meal_plan_id' => $bar->meal_plan_id, 'cancellation_policy_id' => $bar->cancellation_policy_id,
                'default_min_los' => 1, 'sort_order' => 5, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return RatePlan::acrossProperties()->findOrFail($id);
    }

    protected function inventory(RoomType $roomType, string $date, array $values): void
    {
        DB::table('inventory_daily')->updateOrInsert(
            ['room_type_id' => $roomType->id, 'stay_date' => $date],
            $values + ['property_id' => $roomType->property_id, 'total_units' => 2, 'updated_at' => now()],
        );
    }

    protected function ari(int $productId, string $date, array $values): void
    {
        $propertyId = DB::table('room_type_rate_plans')->where('id', $productId)->value('property_id');
        DB::table('ari_daily')->updateOrInsert(
            ['product_id' => $productId, 'stay_date' => $date],
            $values + ['property_id' => $propertyId, 'updated_at' => now()],
        );
    }

    protected function occupancyPrice(int $productId, string $date, int $adults, string $price): void
    {
        $propertyId = DB::table('room_type_rate_plans')->where('id', $productId)->value('property_id');
        DB::table('ari_daily_occupancy')->updateOrInsert(
            ['product_id' => $productId, 'stay_date' => $date, 'adults' => $adults],
            ['property_id' => $propertyId, 'price' => $price, 'updated_at' => now()],
        );
    }

    /** A reservation of $nights nights in one PMS room starting on $date (Phase 4 rows inserted directly). */
    protected function stay(PhysicalUnit $unit, string $date, int $nights, string $status = 'confirmed', string $guest = 'Priya Sharma'): string
    {
        $propertyId = (int) $unit->property_id;
        $sourceId = DB::table('booking_sources')->where('code', 'walk_in')->whereNull('property_id')->value('id')
            ?? DB::table('booking_sources')->insertGetId(['property_id' => null, 'code' => 'walk_in', 'name' => 'Walk-in', 'is_active' => 1]);
        $productId = DB::table('room_type_rate_plans')->where('room_type_id', $unit->room_type_id)->value('id')
            ?? $this->product(RoomType::acrossProperties()->findOrFail($unit->room_type_id), $this->bar(Property::findOrFail($propertyId)));
        $ratePlanId = DB::table('room_type_rate_plans')->where('id', $productId)->value('rate_plan_id');
        $checkOut = date('Y-m-d', strtotime($date." +$nights days"));
        $ref = 'R-'.Str::upper(Str::random(6));

        $reservationId = DB::table('reservations')->insertGetId([
            'public_id' => (string) Str::ulid(), 'property_id' => $propertyId, 'booking_ref' => $ref,
            'status' => $status, 'source_id' => $sourceId, 'guest_name' => $guest, 'check_in' => $date, 'check_out' => $checkOut,
            'nights' => $nights, 'adults' => 2, 'currency_code' => 'INR', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roomId = DB::table('reservation_rooms')->insertGetId([
            'property_id' => $propertyId, 'reservation_id' => $reservationId, 'room_type_id' => $unit->room_type_id,
            'rate_plan_id' => $ratePlanId, 'product_id' => $productId, 'status' => $status, 'check_in' => $date, 'check_out' => $checkOut,
            'adults' => 2, 'rate_snapshot' => '{}', 'room_total' => '1000.00', 'grand_total' => '1000.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < $nights; $i++) {
            DB::table('unit_nights')->insert([
                'unit_id' => $unit->id, 'stay_date' => date('Y-m-d', strtotime($date." +$i days")), 'property_id' => $propertyId,
                'kind' => 'reservation', 'reservation_room_id' => $roomId, 'created_at' => now(),
            ]);
        }

        return $ref;
    }

    protected function day(int $offset, ?string $from = null): string
    {
        return date('Y-m-d', strtotime(($from ?? $this->today()).sprintf(' %+d days', $offset)));
    }
}
