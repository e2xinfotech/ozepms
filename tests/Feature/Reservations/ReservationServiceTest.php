<?php

namespace Tests\Feature\Reservations;

use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationCreated;
use App\Domain\Reservations\Events\ReservationModified;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class ReservationServiceTest extends ReservationTestCase
{
    public function test_create_books_inventory_nights_guest_and_reference(): void
    {
        Event::fake([ReservationCreated::class]);
        $r = $this->book([$this->room($this->dlxBar, 2, 5), $this->room($this->steBar, 2, 4, ['adults' => 1])]);

        $this->assertSame('confirmed', $r->status);
        $this->assertMatchesRegularExpression('/^R-\d{4}001$/', $r->booking_ref);
        $this->assertSame(2, $r->room_count);
        $this->assertSame(3, $r->nights);
        $this->assertSame([1, 1, 1], $this->sold($this->deluxe, 2, 5));
        $this->assertSame([1, 1], $this->sold($this->suite, 2, 4));
        $this->assertSame(5, DB::table('reservation_room_nights')->where('is_active', 1)->count());
        // 3 × 4000 + 2 × 9000 before tax; tax as configured (no rules in the test property → 0)
        $this->assertSame('30000.00', (string) $r->room_total);
        $this->assertSame('John Smith', $r->guest_name);
        $this->assertSame('+447700900123', $r->guest_phone);
        $this->assertSame(1, Guest::acrossProperties()->count());
        $this->assertSame(1, DB::table('reservation_status_history')->where('reservation_id', $r->id)->count());
        Event::assertDispatched(ReservationCreated::class, fn ($e) => $e->reservation->id === $r->id && $e->by?->id === $this->owner->id);
        $this->assertInventoryConsistent();

        $second = $this->book([$this->room($this->dlxBar, 6, 7)]);
        $this->assertStringEndsWith('002', $second->booking_ref);
        $this->assertSame(1, Guest::acrossProperties()->count(), 'same e-mail → same guest');
    }

    public function test_idempotent_create_returns_the_first_booking(): void
    {
        $a = $this->book([$this->room($this->dlxBar, 2, 4)], ['idempotency_key' => 'abc-123']);
        $b = $this->book([$this->room($this->dlxBar, 2, 4)], ['idempotency_key' => 'abc-123']);

        $this->assertSame($a->id, $b->id);
        $this->inProperty($this->property);
        $service = $this->service();
        $service->create($this->property->fresh(), ['idempotency_key' => 'abc-123', 'guest' => ['first_name' => 'X'], 'rooms' => [$this->room($this->dlxBar, 2, 4)]], $this->owner);
        $this->assertTrue($service->replayed);
        $this->assertSame(1, Reservation::acrossProperties()->count());
        $this->assertSame([1, 1], $this->sold($this->deluxe, 2, 4));
    }

    public function test_last_room_cannot_be_double_booked(): void
    {
        $this->book([$this->room($this->steBar, 2, 4)]);
        try {
            $this->book([$this->room($this->steBar, 3, 5)]);
            $this->fail('Overbooked');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rooms.0.room_type_id', $e->errors());
        }
        $this->assertSame(1, Reservation::acrossProperties()->count());
        $this->assertInventoryConsistent();
    }

    public function test_inquiry_holds_no_inventory_until_confirmed(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)], ['status' => 'inquiry']);
        $this->assertSame([0, 0], $this->sold($this->deluxe, 2, 4));
        $this->inProperty($this->property);
        $this->service()->confirm($r, $this->owner);
        $this->assertSame([1, 1], $this->sold($this->deluxe, 2, 4));
        $this->assertSame('confirmed', $r->fresh()->status);
        $this->assertInventoryConsistent();
    }

    public function test_modify_dates_rooms_and_rates_moves_only_the_delta(): void
    {
        Event::fake([ReservationModified::class]);
        $r = $this->book([$this->room($this->dlxBar, 2, 5)]);
        $roomId = ReservationRoom::acrossProperties()->where('reservation_id', $r->id)->value('id');
        $this->inProperty($this->property);

        $this->service()->modify($r, ['rooms' => [
            $this->room($this->dlxBar, 3, 7, ['id' => $roomId]),
            $this->room($this->steBar, 3, 4),
        ]], $this->owner);
        $r->refresh();

        $this->assertSame([0, 1, 1, 1, 1], $this->sold($this->deluxe, 2, 7));
        $this->assertSame([1], $this->sold($this->suite, 3, 4));
        $this->assertSame($this->day(3)->toDateString(), $r->check_in->toDateString());
        $this->assertSame(2, $r->room_count);
        $this->assertSame('25000.00', (string) $r->room_total);
        $this->assertSame(1, DB::table('reservation_room_nights')->where('reservation_room_id', $roomId)->where('is_active', 0)->count());
        Event::assertDispatched(ReservationModified::class, fn ($e) => isset($e->changes['dates'], $e->changes['rooms']['added'], $e->changes['rates']));
        $this->assertInventoryConsistent();

        // Remove the suite again, price the deluxe manually.
        $this->service()->modify($r->fresh(), ['rooms' => [$this->room($this->dlxBar, 3, 7, ['id' => $roomId, 'rate' => '3500'])]], $this->owner);
        $this->assertSame([0], $this->sold($this->suite, 3, 4));
        $this->assertSame('14000.00', (string) $r->fresh()->room_total);
        $this->assertInventoryConsistent();
    }

    public function test_modify_that_needs_unavailable_rooms_changes_nothing(): void
    {
        $other = $this->book([$this->room($this->steBar, 5, 6)]);
        $r = $this->book([$this->room($this->steBar, 2, 4)]);
        $roomId = ReservationRoom::acrossProperties()->where('reservation_id', $r->id)->value('id');
        $this->inProperty($this->property);
        try {
            $this->service()->modify($r, ['rooms' => [$this->room($this->steBar, 2, 6, ['id' => $roomId])]], $this->owner);
            $this->fail('Extended into a sold-out night');
        } catch (ValidationException) {
        }
        $this->assertSame([1, 1, 0, 1], $this->sold($this->suite, 2, 6));
        $this->assertSame($this->day(4)->toDateString(), $r->fresh()->check_out->toDateString());
        $this->assertInventoryConsistent();
        $this->assertNotNull($other);
    }

    public function test_cancel_releases_inventory_and_reports_the_policy_fee(): void
    {
        Event::fake([ReservationCancelled::class]);
        $policy = DB::table('rate_plans')->where('id', $this->bar()->id)->value('cancellation_policy_id');
        DB::table('cancellation_policy_rules')->where('policy_id', $policy)->delete();
        DB::table('cancellation_policy_rules')->insert(['policy_id' => $policy, 'applies_to' => 'cancellation', 'hours_before_arrival' => 72, 'charge_type' => 'first_night', 'charge_value' => null]);

        $late = $this->book([$this->room($this->dlxBar, 1, 3)]);
        $early = $this->book([$this->room($this->dlxBar, 20, 22)]);
        $this->inProperty($this->property);
        $this->service()->cancel($late, 'Guest changed plans', $this->owner);
        $this->service()->cancel($early, null, $this->owner);

        $this->assertSame('4200.00', (string) $late->fresh()->cancellation_fee, 'first night incl. 5 % GST');
        $this->assertSame('0.00', (string) $early->fresh()->cancellation_fee);
        $this->assertSame([0, 0], $this->sold($this->deluxe, 1, 3));
        Event::assertDispatched(ReservationCancelled::class, fn ($e) => $e->reservation->id === $late->id && $e->fee === '4200.00' && $e->reason === 'Guest changed plans');
        $this->assertInventoryConsistent();

        $this->expectException(ValidationException::class);
        $this->service()->cancel($late->fresh(), null, $this->owner);
    }

    public function test_assign_check_in_check_out_flow(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 0, 2)]);
        $room = ReservationRoom::acrossProperties()->where('reservation_id', $r->id)->first();
        [$u1, $u2] = $this->units($this->deluxe)->all();
        $this->inProperty($this->property);

        try {
            $this->service()->checkIn($r, null, $this->owner);
            $this->fail('Checked in without a room');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unit_id', $e->errors());
        }

        $this->service()->assignUnit($r, $room, $u1, $this->owner);
        $this->assertSame(2, DB::table('unit_nights')->where('unit_id', $u1->id)->count());

        // A second booking cannot get the same PMS room.
        $other = $this->book([$this->room($this->dlxBar, 1, 3)]);
        $this->inProperty($this->property);
        $otherRoom = ReservationRoom::acrossProperties()->where('reservation_id', $other->id)->first();
        try {
            $this->service()->assignUnit($other, $otherRoom, $u1, $this->owner);
            $this->fail('Double assignment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unit_id', $e->errors());
        }

        DB::table('physical_units')->where('id', $u1->id)->update(['housekeeping_status' => 'dirty']);
        try {
            $this->service()->checkIn($r->fresh(), null, $this->owner);
            $this->fail('Checked into a dirty room');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unit_id', $e->errors());
        }
        DB::table('physical_units')->where('id', $u1->id)->update(['housekeeping_status' => 'clean']);

        $this->service()->checkIn($r->fresh(), null, $this->owner);
        $this->assertSame('checked_in', $r->fresh()->status);

        // Move to another room from tonight on.
        $this->service()->assignUnit($r->fresh(), $room->fresh(), $u2, $this->owner);
        $this->assertSame(2, DB::table('unit_nights')->where('unit_id', $u2->id)->where('reservation_room_id', $room->id)->count());

        // Leaving today, a day early: the second night is released.
        $this->service()->checkOut($r->fresh(), null, $this->owner);
        $r->refresh();
        $this->assertSame('checked_out', $r->status);
        $this->assertSame(1, (int) $r->fresh()->nights);
        $this->assertSame('dirty', DB::table('physical_units')->where('id', $u2->id)->value('housekeeping_status'));
        $this->assertInventoryConsistent();
    }

    public function test_no_show_only_from_arrival_day(): void
    {
        $future = $this->book([$this->room($this->dlxBar, 3, 4)]);
        $today = $this->book([$this->room($this->dlxBar, 0, 2)]);
        $this->inProperty($this->property);
        try {
            $this->service()->noShow($future, $this->owner);
            $this->fail('No-show before arrival');
        } catch (ValidationException) {
        }
        $this->service()->noShow($today, $this->owner);
        $this->assertSame('no_show', $today->fresh()->status);
        $this->assertSame([0, 0], $this->sold($this->deluxe, 0, 2));
        $this->assertInventoryConsistent();
    }

    public function test_restrictions_block_a_booking(): void
    {
        $this->apply(['date_from' => $this->day(10)->toDateString(), 'date_to' => $this->day(10)->toDateString(), 'product_ids' => [$this->dlxBar->id], 'cta' => true]);
        $this->expectException(ValidationException::class);
        $this->book([$this->room($this->dlxBar, 10, 12)]);
    }

    public function test_other_property_product_is_refused(): void
    {
        $otherRt = $this->makeRoomType(['code' => 'OTH'], 1, $this->other);
        $otherProduct = $this->product($otherRt, $this->bar($this->other), ['default_price' => '1000']);
        $this->expectException(ValidationException::class);
        $this->book([$this->room($otherProduct, 2, 3)]);
        app(PropertyContext::class)->clear();
    }
}
