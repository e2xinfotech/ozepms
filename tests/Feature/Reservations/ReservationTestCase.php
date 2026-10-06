<?php

namespace Tests\Feature\Reservations;

use App\Domain\Reservations\ReservationService;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inventory\InventoryTestCase;

/** A property with two bookable room types (DLX: 3 rooms, STE: 1 room) priced through BAR. */
abstract class ReservationTestCase extends InventoryTestCase
{
    protected RoomType $deluxe;

    protected RoomType $suite;

    protected Product $dlxBar;

    protected Product $steBar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deluxe = $this->makeRoomType(['code' => 'DLX', 'name' => 'Deluxe Room'], 3);
        $this->suite = $this->makeRoomType(['code' => 'STE', 'name' => 'Suite'], 1);
        $this->dlxBar = $this->product($this->deluxe, $this->bar(), ['default_price' => '4000.00']);
        $this->steBar = $this->product($this->suite, $this->bar(), ['default_price' => '9000.00']);
    }

    protected function service(): ReservationService
    {
        return app(ReservationService::class);
    }

    protected function room(Product $product, int $in, int $out, array $extra = []): array
    {
        return array_merge(['product' => $product, 'check_in' => $this->day($in), 'check_out' => $this->day($out), 'adults' => 2, 'children' => 0, 'infants' => 0], $extra);
    }

    /** Books through the service as the owner, inside the main property. */
    protected function book(array $rooms, array $data = [], ?Property $property = null): Reservation
    {
        $property ??= $this->property;
        $this->inProperty($property);
        $reservation = $this->service()->create($property->fresh(), array_merge([
            'status' => 'confirmed',
            'guest' => ['first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john.smith@example.com', 'phone' => '+44 7700 900123'],
            'rooms' => $rooms,
        ], $data), $this->owner);
        app(PropertyContext::class)->clear();

        return $reservation->fresh();
    }

    protected function units(RoomType $roomType): \Illuminate\Support\Collection
    {
        return PhysicalUnit::acrossProperties()->where('room_type_id', $roomType->id)->orderBy('id')->get();
    }

    /** inventory_daily.sold per night of [in, out) */
    protected function sold(RoomType $roomType, int $in, int $out): array
    {
        return DB::table('inventory_daily')->where('room_type_id', $roomType->id)
            ->whereBetween('stay_date', [$this->day($in)->toDateString(), $this->day($out - 1)->toDateString()])
            ->orderBy('stay_date')->pluck('sold')->map(fn ($v) => (int) $v)->all();
    }

    /** The invariant: sold = active reservation nights, for every room type and night. */
    protected function assertInventoryConsistent(): void
    {
        $drift = DB::select(<<<'SQL'
SELECT i.room_type_id, i.stay_date, i.sold, COALESCE(n.c, 0) AS nights
  FROM inventory_daily i
  LEFT JOIN (SELECT room_type_id, stay_date, COUNT(*) AS c FROM reservation_room_nights WHERE is_active = 1 GROUP BY room_type_id, stay_date) n
    ON n.room_type_id = i.room_type_id AND n.stay_date = i.stay_date
 WHERE i.sold <> COALESCE(n.c, 0)
SQL);
        $this->assertSame([], $drift, 'inventory_daily.sold differs from active reservation nights: '.json_encode($drift));
    }

    protected function actingMember(string $role): User
    {
        return $this->member($role);
    }
}
