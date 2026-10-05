<?php

namespace Tests\Feature\Accommodation;

use App\Domain\Accommodation\PhysicalUnitService;
use App\Domain\Accommodation\RoomTypeService;
use App\Models\PhysicalUnit;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Support\PropertyContext;
use Database\Seeders\AccommodationReferenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared set-up for the rooms, rates and taxes tests: reference data, two properties
 * (the second one is the "other tenant") and helpers to create room types and bookings.
 */
abstract class AccommodationTestCase extends TestCase
{
    protected Property $property;

    protected User $owner;

    protected Property $other;

    protected User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccommodationReferenceSeeder::class);
        [$this->property, $this->owner] = $this->createPropertyWithOwner(['name' => 'Main Hotel']);
        [$this->other, $this->otherOwner] = $this->createPropertyWithOwner(['name' => 'Other Hotel']);
        // Reload so every column is present (models are strict about missing attributes).
        $this->owner = $this->owner->fresh();
        $this->otherOwner = $this->otherOwner->fresh();
    }

    /** The JSON page payload escapes slashes; this is the page name as it appears in the HTML. */
    protected function pageName(string $page): string
    {
        return str_replace('/', '\\/', $page);
    }

    /** A member of the main property with one of the system roles. */
    protected function member(string $role): User
    {
        return $this->addMember($this->property, $role)->fresh();
    }

    protected function api(string $path): string
    {
        return '/web-api/p/'.$this->property->code.$path;
    }

    protected function page(string $path): string
    {
        return '/p/'.$this->property->code.$path;
    }

    /** Creates a room type with $rooms PMS rooms in the given property (the main one by default). */
    protected function makeRoomType(array $attributes = [], int $rooms = 2, ?Property $property = null): RoomType
    {
        $property ??= $this->property;
        $this->inProperty($property);
        $roomType = app(RoomTypeService::class)->create(array_merge([
            'code' => 'RT'.Str::upper(Str::random(3)),
            'name' => 'Room '.Str::random(4),
            'category' => 'deluxe',
            'base_adults' => 2,
            'max_adults' => 3,
            'max_children' => 2,
            'max_infants' => 1,
            'max_occupancy' => 4,
            'quantity' => $rooms,
        ], $attributes));
        app(PropertyContext::class)->clear();

        return $roomType->refresh();
    }

    protected function bar(?Property $property = null): RatePlan
    {
        return RatePlan::acrossProperties()->where('property_id', ($property ?? $this->property)->id)->where('code', 'BAR')->firstOrFail();
    }

    protected function firstUnit(RoomType $roomType): PhysicalUnit
    {
        return PhysicalUnit::acrossProperties()->where('room_type_id', $roomType->id)->orderBy('id')->firstOrFail();
    }

    /** Puts a guest in the room for one night (rows owned by the reservations module, inserted directly). */
    protected function reserveNight(PhysicalUnit $unit, string $date): void
    {
        $propertyId = (int) $unit->property_id;
        $sourceId = DB::table('booking_sources')->where('code', 'walk_in')->whereNull('property_id')->value('id')
            ?? DB::table('booking_sources')->insertGetId(['property_id' => null, 'code' => 'walk_in', 'name' => 'Walk-in', 'is_active' => 1]);
        $productId = DB::table('room_type_rate_plans')->where('room_type_id', $unit->room_type_id)->value('id')
            ?? $this->linkBar($unit);
        $ratePlanId = DB::table('room_type_rate_plans')->where('id', $productId)->value('rate_plan_id');
        $checkOut = date('Y-m-d', strtotime($date.' +1 day'));

        $reservationId = DB::table('reservations')->insertGetId([
            'public_id' => (string) Str::ulid(), 'property_id' => $propertyId, 'booking_ref' => 'R-'.Str::upper(Str::random(6)),
            'status' => 'confirmed', 'source_id' => $sourceId, 'guest_name' => 'John Smith', 'check_in' => $date, 'check_out' => $checkOut,
            'nights' => 1, 'adults' => 2, 'currency_code' => 'INR', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roomId = DB::table('reservation_rooms')->insertGetId([
            'property_id' => $propertyId, 'reservation_id' => $reservationId, 'room_type_id' => $unit->room_type_id,
            'rate_plan_id' => $ratePlanId, 'product_id' => $productId, 'status' => 'confirmed', 'check_in' => $date, 'check_out' => $checkOut,
            'adults' => 2, 'rate_snapshot' => '{}', 'room_total' => '1000.00', 'grand_total' => '1000.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('unit_nights')->insert([
            'unit_id' => $unit->id, 'stay_date' => $date, 'property_id' => $propertyId, 'kind' => 'reservation',
            'reservation_room_id' => $roomId, 'created_at' => now(),
        ]);
    }

    private function linkBar(PhysicalUnit $unit): int
    {
        return DB::table('room_type_rate_plans')->insertGetId([
            'public_id' => (string) Str::ulid(), 'property_id' => $unit->property_id, 'room_type_id' => $unit->room_type_id,
            'rate_plan_id' => RatePlan::acrossProperties()->where('property_id', $unit->property_id)->where('code', 'BAR')->value('id'),
            'pricing_mode' => 'manual', 'default_price' => '1000.00', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function today(?Property $property = null): string
    {
        $property ??= $this->property;

        return now($property->timezone)->toDateString();
    }
}
