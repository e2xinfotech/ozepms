<?php

namespace Tests\Feature\Reservations;

use Database\Seeders\AccommodationDemoSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationDemoSeederTest extends TestCase
{
    public function test_demo_reservations_are_consistent_and_the_seeder_is_idempotent(): void
    {
        config(['ozepms.inventory.horizon_days' => 60]);
        // Creates the demo properties through DemoSeeder, which runs the module demo seeders.
        $this->seed(AccommodationDemoSeeder::class);

        $total = DB::table('reservations')->count();
        $this->assertGreaterThanOrEqual(36, $total);
        foreach (['checked_in', 'checked_out', 'confirmed', 'pending', 'inquiry', 'cancelled', 'no_show'] as $status) {
            $this->assertGreaterThan(0, DB::table('reservations')->where('status', $status)->count(), $status);
        }
        $this->assertSame([], DB::select(<<<'SQL'
SELECT i.room_type_id, i.stay_date FROM inventory_daily i
  LEFT JOIN (SELECT room_type_id, stay_date, COUNT(*) c FROM reservation_room_nights WHERE is_active = 1 GROUP BY room_type_id, stay_date) n
    ON n.room_type_id = i.room_type_id AND n.stay_date = i.stay_date
 WHERE i.sold <> COALESCE(n.c, 0)
SQL));
        // Every in-house guest has a PMS room tonight.
        $this->assertSame(0, DB::table('reservation_rooms')->where('status', 'checked_in')
            ->whereNotExists(fn ($q) => $q->from('unit_nights')->whereColumn('unit_nights.reservation_room_id', 'reservation_rooms.id'))->count());

        $this->seed(ReservationDemoSeeder::class);
        $this->assertSame($total, DB::table('reservations')->count());
    }
}
