<?php

namespace Database\Seeders;

use App\Domain\Accommodation\InProperty;
use App\Domain\Reservations\ReservationService;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Demo reservations for P1001 / P1002 (about 20 each), created through ReservationService so
 * inventory, nights and PMS room assignments stay consistent: guests in-house today, arrivals
 * and departures today, upcoming stays, a group, pending, draft, cancelled, no-show and past
 * stays. Idempotent: every booking has a fixed idempotency key, a second run returns the
 * existing ones. Stays that began before today are priced manually (no daily rates in the past).
 */
class ReservationDemoSeeder extends Seeder
{

    /** first, last, e-mail, phone, nationality, id type */
    private const GUESTS = [
        ['John', 'Smith', 'john.smith@example.com', '+44 7700 900123', 'GB', 'passport'],
        ['Priya', 'Sharma', 'priya.sharma@example.com', '+91 98765 43210', 'IN', 'national_id'],
        ['Ahmed', 'Al Mansoori', 'ahmed@example.com', '+971 50 123 4567', 'AE', 'national_id'],
        ['Emily', 'Johnson', 'emily.j@example.com', '+1 415 555 0123', 'US', 'passport'],
        ['Sophie', 'Dubois', 'sophie@example.com', '+33 6 12 34 56 78', 'FR', 'passport'],
        ['Carlos', 'Mendez', 'carlos@example.com', '+34 600 123 456', 'ES', 'passport'],
        ['Chen', 'Wei', 'chen.wei@example.com', '+86 138 0000 1234', 'CN', 'passport'],
        ['Marco', 'Rossi', 'marco.rossi@example.com', '+39 320 123 4567', 'IT', 'passport'],
        ['Li', 'Na', 'lina@example.com', '+86 138 0000 5678', 'CN', 'passport'],
        ['Fatima', 'Khan', 'fatima.khan@example.com', '+971 55 765 4321', 'AE', 'national_id'],
        ['Daniel', 'Kim', 'daniel@example.com', '+82 10 1234 5678', 'KR', 'passport'],
        ['Anna', 'Müller', 'anna.mueller@example.com', '+49 151 2345 6789', 'DE', 'passport'],
        ['Rahul', 'Verma', 'rahul.verma@example.com', '+91 99887 76655', 'IN', 'driving_licence'],
        ['Olivia', 'Brown', 'olivia.brown@example.com', '+44 7700 900456', 'GB', 'passport'],
        ['Yuki', 'Tanaka', 'yuki.tanaka@example.com', '+81 90 1234 5678', 'JP', 'passport'],
        ['Business', 'Corp', 'travel@businesscorp.example', '+971 4 555 0101', 'AE', null],
    ];

    /**
     * [guest index, room type code, plan code, check-in offset, nights, adults, children, scenario, rooms, source]
     * Scenarios: in_house, departure (in-house leaving today), arrival, arrival_assigned, upcoming, pending, draft,
     * cancelled, no_show, past.
     */
    private const BOOKINGS = [
        [0, 'DLX', 'BAR', -2, 4, 2, 0, 'in_house', 1, 'direct'],
        [1, 'DLX', 'BB', -1, 3, 2, 1, 'in_house', 1, 'phone'],
        [2, 'SUP', 'BAR', -3, 5, 2, 0, 'in_house', 1, 'ota'],
        [3, 'FAM', 'BB', -2, 2, 2, 2, 'departure', 1, 'booking_engine'],
        [4, 'SUP', 'BAR', -1, 1, 1, 0, 'departure', 1, 'email'],
        [5, 'DLX', 'BAR', 0, 3, 2, 1, 'arrival_assigned', 1, 'direct'],
        [6, 'SUP', 'BB', 0, 2, 2, 0, 'arrival', 1, 'ota'],
        [7, 'FAM', 'BAR', 0, 4, 2, 2, 'arrival', 1, 'travel_agent'],
        [8, 'DLX', 'BB', 2, 3, 2, 0, 'upcoming', 1, 'booking_engine'],
        [9, 'FAM', 'BB', 4, 4, 2, 2, 'upcoming', 1, 'direct'],
        [10, 'SUP', 'BAR', 6, 2, 2, 0, 'upcoming', 1, 'phone'],
        [11, 'DLX', 'BAR', 9, 5, 2, 0, 'upcoming', 1, 'ota'],
        [15, 'DLX', 'BAR', 11, 3, 2, 0, 'upcoming', 3, 'corporate'],
        [12, 'SUP', 'BB', 5, 2, 2, 0, 'pending', 1, 'email'],
        [13, 'DLX', 'BB', 17, 4, 2, 1, 'draft', 1, 'phone'],
        [14, 'FAM', 'BAR', 8, 3, 2, 1, 'cancelled', 1, 'ota'],
        [3, 'SUP', 'BAR', 12, 2, 1, 0, 'cancelled', 1, 'direct'],
        [6, 'DLX', 'BAR', -1, 2, 2, 0, 'no_show', 1, 'ota'],
        [0, 'FAM', 'BB', -12, 3, 2, 2, 'past', 1, 'direct'],
        [7, 'DLX', 'BAR', -9, 2, 2, 0, 'past', 1, 'booking_engine'],
    ];

    public function run(): void
    {
        $properties = AccommodationDemoSeeder::demoProperties();
        if ($properties->isEmpty()) {
            $this->command?->warn('No demo properties found; run the DemoSeeder first.');

            return;
        }
        $user = User::query()->where('email', 'manager@demo.ozepms.test')->first();

        foreach ($properties as $property) {
            $count = InProperty::run($property, fn () => $this->seedProperty($property->fresh(), $user));
            $this->command?->info("{$count} demo reservations ready for {$property->code}.");
        }
    }

    private function seedProperty(Property $property, ?User $user): int
    {
        $service = app(ReservationService::class);
        $today = $service->today($property);
        $products = Product::query()->join('room_types as rt', 'rt.id', '=', 'room_type_rate_plans.room_type_id')
            ->join('rate_plans as rp', 'rp.id', '=', 'room_type_rate_plans.rate_plan_id')
            ->where('room_type_rate_plans.is_active', true)
            ->get(['room_type_rate_plans.*', 'rt.code as rt_code', 'rp.code as rp_code'])
            ->keyBy(fn ($p) => $p->rt_code.'-'.$p->rp_code);
        if ($products->isEmpty()) {
            return 0;
        }
        // Rooms the guests stay in must be ready for check-in.
        PhysicalUnit::query()->update(['housekeeping_status' => 'clean']);

        $done = 0;
        foreach (self::BOOKINGS as $n => [$g, $rt, $plan, $offset, $nights, $adults, $children, $scenario, $roomCount, $source]) {
            $product = $products->get("{$rt}-{$plan}") ?? $products->first(fn ($p) => $p->rt_code === $rt);
            if ($product === null) {
                continue;
            }
            $key = sprintf('demo-%s-%02d', $property->code, $n);
            if (Reservation::query()->where('idempotency_key', $key)->exists()) {
                $done++;

                continue;
            }
            $in = $today->addDays($offset);
            $out = $in->addDays($nights);
            $guest = self::GUESTS[$g];
            // Stays that started in the past have no daily rate any more: price them like the default.
            $rate = $in->lessThan($today) ? (string) ($product->default_price ?? '0') : null;
            $rooms = [];
            for ($i = 0; $i < $roomCount; $i++) {
                $rooms[] = ['product' => Product::query()->find($product->id), 'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children, 'rate' => $rate];
            }

            try {
                $r = $service->create($property, [
                    'idempotency_key' => $key,
                    'status' => match ($scenario) { 'pending' => 'pending', 'draft' => 'inquiry', default => 'confirmed' },
                    'source_id' => DB::table('booking_sources')->whereNull('property_id')->where('code', $source)->value('id'),
                    'allow_past' => $in->lessThan($today),
                    'guest' => [
                        'first_name' => $guest[0], 'last_name' => $guest[1], 'email' => $guest[2], 'phone' => $guest[3], 'nationality_iso2' => $guest[4],
                        'country_iso2' => $guest[4], 'id_type' => $guest[5], 'id_number' => $guest[5] ? strtoupper($guest[4]).sprintf('%07d', 1234567 + $g * 7919) : null,
                        'guest_type' => $g === 15 ? 'corporate' : 'individual', 'company_name' => $g === 15 ? 'Business Corp' : null, 'is_vip' => in_array($g, [0, 2], true),
                        'tags' => $g === 0 ? ['VIP', 'Repeat Guest'] : ($g === 15 ? ['Business'] : []),
                    ],
                    'rooms' => $rooms,
                    'purpose' => $g === 15 ? 'business' : 'leisure',
                    'special_requests' => $n % 4 === 0 ? 'High floor, late check-in if possible' : null,
                    'company_name' => $g === 15 ? 'Business Corp' : null,
                ], $user);
            } catch (ValidationException $e) {
                $this->command?->warn("Skipped demo booking {$key}: ".collect($e->errors())->flatten()->first());

                continue;
            }
            $this->finish($service, $r, $scenario, $user, $today);
            $done++;
        }

        return $done;
    }

    /** Brings a booking into the state of its scenario through the service (or by status only for past stays). */
    private function finish(ReservationService $service, Reservation $r, string $scenario, ?User $user, CarbonImmutable $today): void
    {
        $assign = function () use ($service, $r, $user) {
            foreach (ReservationRoom::query()->where('reservation_id', $r->id)->get() as $room) {
                if (DB::table('unit_nights')->where('reservation_room_id', $room->id)->exists()) {
                    continue;
                }
                $unit = $service->freeUnits($room->room_type_id, $room->check_in, $room->check_out)->first();
                if ($unit !== null) {
                    $service->assignUnit($r->fresh(), $room, $unit, $user);
                }
            }
        };

        try {
            match ($scenario) {
                'in_house' => (function () use ($assign, $service, $r, $user) {
                    $assign();
                    $service->checkIn($r->fresh(), null, $user);
                })(),
                // Leaving today: checked in on the arrival day (status only; nights and inventory are already right).
                'departure' => (function () use ($assign, $r, $user) {
                    $assign();
                    $this->markStatus($r, 'checked_in', $user);
                })(),
                'arrival_assigned', 'upcoming' => $assign(),
                'cancelled' => $service->cancel($r->fresh(), 'Guest changed travel plans', $user),
                'no_show' => $service->noShow($r->fresh(), $user),
                'past' => (function () use ($assign, $r, $user) {
                    $assign();
                    $this->markStatus($r, 'checked_out', $user);
                })(),
                default => null,
            };
        } catch (ValidationException $e) {
            $this->command?->warn("Demo booking {$r->booking_ref} left as {$r->fresh()->status}: ".collect($e->errors())->flatten()->first());
        }
    }

    /**
     * Stays that began before today cannot go through check-in / check-out any more; only the
     * status moves on (nights and inventory are the same for confirmed, in-house and checked-out).
     */
    public function markStatus(Reservation $r, string $status, ?User $user): void
    {
        $rooms = ['status' => $status, 'checked_in_at' => $r->check_in->setTime(14, 30), 'checked_in_by' => $user?->id];
        if ($status === 'checked_out') {
            $rooms += ['checked_out_at' => $r->check_out->setTime(10, 45), 'checked_out_by' => $user?->id];
        }
        ReservationRoom::query()->where('reservation_id', $r->id)->update($rooms);
        $r->forceFill(['status' => $status])->save();
        $steps = $status === 'checked_out' ? ['checked_in', 'checked_out'] : ['checked_in'];
        foreach ($steps as $i => $to) {
            DB::table('reservation_status_history')->insert([
                'reservation_id' => $r->id, 'from_status' => $i === 0 ? 'confirmed' : 'checked_in', 'to_status' => $to,
                'user_id' => $user?->id, 'created_at' => $i === 0 ? $r->check_in->setTime(14, 30) : $r->check_out->setTime(10, 45),
            ]);
        }
    }
}
