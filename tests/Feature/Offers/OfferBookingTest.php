<?php

namespace Tests\Feature\Offers;

use App\Models\ReservationRoom;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Offers inside real bookings: price before tax, frozen applications, redemptions, promo codes,
 * manual prices, modifications (kept nights) and cancellations. DLX BAR = 4000 per night.
 */
class OfferBookingTest extends OfferTestCase
{
    private function apps(int $reservationId): \Illuminate\Support\Collection
    {
        return DB::table('offer_applications')->where('reservation_id', $reservationId)->orderBy('id')->get();
    }

    private function roomOf(int $reservationId): ReservationRoom
    {
        return ReservationRoom::acrossProperties()->where('reservation_id', $reservationId)->firstOrFail();
    }

    public function test_automatic_offer_lowers_the_price_before_tax_and_is_frozen_on_the_booking(): void
    {
        $before = $this->book([$this->room($this->dlxBar, 3, 5)]);
        $offer = $this->offer(['name' => 'Twenty', 'discount_value' => '20', 'max_redemptions' => 5]);
        $r = $this->book([$this->room($this->dlxBar, 3, 5)]);

        $room = $this->roomOf($r->id);
        $this->assertSame('1600.00', (string) $room->discount_total, '20 % of 2 × 4000');
        $this->assertSame('6400.00', (string) $room->room_total, 'taxable amount after the discount');
        $this->assertTrue((float) $r->tax_total < (float) $before->tax_total, 'tax is worked out on the lower price');
        $night = DB::table('reservation_room_nights')->where('reservation_room_id', $room->id)->orderBy('stay_date')->first();
        $this->assertSame(['4000.00', '800.00', '3200.00'], [(string) $night->base_price, (string) $night->discount, (string) $night->net_price]);

        $apps = $this->apps($r->id);
        $this->assertCount(1, $apps);
        $this->assertSame('1600.00', (string) $apps[0]->discount_amount);
        $snap = json_decode($apps[0]->snapshot, true);
        $this->assertSame('Twenty', $snap['name']);
        $this->assertCount(2, $snap['nightly']);
        $this->assertSame(1, (int) $offer->fresh()->redemptions);
        $this->assertCount(0, $this->apps($before->id));

        // The detail page lists the offer; room charges are shown before it, the subtotal after it.
        $this->actingAs($this->owner)->getJson($this->api('/reservations/'.$r->public_id))->assertOk()
            ->assertJsonPath('reservation.offers.0.name', 'Twenty')->assertJsonPath('reservation.offers.0.amount', '1600.00')
            ->assertJsonPath('reservation.totals.room_total', '8000.00')->assertJsonPath('reservation.totals.offer_discount', '1600.00')
            ->assertJsonPath('reservation.totals.subtotal', '6400.00');

        // Later edits of the offer never change the booking.
        $offer->update(['discount_value' => '50']);
        $this->assertSame('1600.00', (string) $this->roomOf($r->id)->fresh()->discount_total);
    }

    public function test_promo_codes_on_create(): void
    {
        $this->offer(['name' => 'Promo', 'promo_code' => 'SUMMER', 'discount_value' => '10', 'min_nights' => 2]);
        $this->assertCount(0, $this->apps($this->book([$this->room($this->dlxBar, 3, 5)])->id), 'promo offers need the code');

        try {
            $this->book([$this->room($this->dlxBar, 3, 5)], ['promo_code' => 'WINTER']);
            $this->fail('unknown code accepted');
        } catch (ValidationException $e) {
            $this->assertSame([__('offers.reasons.unknown_code')], $e->errors()['promo_code']);
        }
        try {
            $this->book([$this->room($this->dlxBar, 3, 4)], ['promo_code' => 'SUMMER']);
            $this->fail('too short accepted');
        } catch (ValidationException $e) {
            $this->assertSame([__('offers.reasons.min_nights')], $e->errors()['promo_code']);
        }
        $r = $this->book([$this->room($this->dlxBar, 3, 5)], ['promo_code' => 'summer']);
        $this->assertSame('800.00', (string) $this->roomOf($r->id)->discount_total);
        $this->assertSame('SUMMER', $this->service()->bookedPromo($r));
    }

    public function test_manual_prices_and_past_imports_get_no_offer(): void
    {
        $this->offer(['discount_value' => '20']);
        $r = $this->book([$this->room($this->dlxBar, 3, 5, ['rate' => '3000'])]);
        $this->assertSame('0.00', (string) $this->roomOf($r->id)->discount_total);
        $this->assertCount(0, $this->apps($r->id));
    }

    public function test_used_up_offer_cannot_be_booked_twice(): void
    {
        $offer = $this->offer(['promo_code' => 'ONCE', 'max_redemptions' => 1]);
        $this->book([$this->room($this->dlxBar, 3, 4)], ['promo_code' => 'ONCE']);
        try {
            $this->book([$this->room($this->dlxBar, 6, 7)], ['promo_code' => 'ONCE']);
            $this->fail('redeemed twice');
        } catch (ValidationException $e) {
            $this->assertSame([__('offers.reasons.redeemed')], $e->errors()['promo_code']);
        }
        $this->assertSame(1, (int) $offer->fresh()->redemptions);
    }

    public function test_modify_keeps_frozen_nights_and_cancel_gives_the_redemption_back(): void
    {
        $offer = $this->offer(['name' => 'Ten', 'discount_value' => '10', 'max_redemptions' => 10]);
        $r = $this->book([$this->room($this->dlxBar, 3, 5)]);
        $room = $this->roomOf($r->id);
        $this->assertSame('800.00', (string) $room->discount_total);

        // The offer gets bigger, then the stay is extended by a night: the two booked nights keep 10 %, the new night gets 30 %.
        $offer->update(['discount_value' => '30']);
        $this->inProperty($this->property);
        $this->service()->modify($r->fresh(), ['rooms' => [[
            'id' => $room->id, 'product' => $this->dlxBar, 'check_in' => $this->day(3), 'check_out' => $this->day(6),
            'adults' => 2, 'children' => 0, 'infants' => 0,
        ]]], $this->owner);
        $room->refresh();
        $this->assertSame('2000.00', (string) $room->discount_total, '400 + 400 + 1200');
        $apps = $this->apps($r->id);
        $this->assertCount(1, $apps);
        $this->assertSame('2000.00', (string) $apps[0]->discount_amount);
        $this->assertSame(1, (int) $offer->fresh()->redemptions, 'an edit is not a new redemption');

        $this->service()->cancel($r->fresh(), 'Plans changed', $this->owner);
        $this->assertSame(0, (int) $offer->fresh()->redemptions);
        $this->assertCount(1, $this->apps($r->id), 'history kept');
    }

    public function test_changing_the_promo_code_on_edit_reworks_all_nights(): void
    {
        $this->offer(['name' => 'Promo', 'promo_code' => 'SAVE', 'discount_value' => '25']);
        $r = $this->book([$this->room($this->dlxBar, 3, 5)], ['promo_code' => 'SAVE']);
        $room = $this->roomOf($r->id);
        $this->assertSame('2000.00', (string) $room->discount_total);

        $this->inProperty($this->property);
        $spec = ['id' => $room->id, 'product' => $this->dlxBar, 'check_in' => $this->day(3), 'check_out' => $this->day(5), 'adults' => 2, 'children' => 0, 'infants' => 0];
        $this->service()->modify($r->fresh(), ['rooms' => [$spec], 'promo_code' => null], $this->owner);
        $this->assertSame('0.00', (string) $room->fresh()->discount_total, 'code removed: the discount goes');
        $this->assertCount(0, $this->apps($r->id));
        $this->assertSame('8000.00', (string) $room->fresh()->room_total);
    }

    public function test_quote_endpoint_reports_offers_and_promo_feedback(): void
    {
        $this->offer(['name' => 'Promo', 'promo_code' => 'SAVE', 'discount_value' => '25']);
        $rooms = [['room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $this->bar()->public_id,
            'check_in' => $this->day(3)->toDateString(), 'check_out' => $this->day(5)->toDateString(), 'adults' => 2]];
        $this->actingAs($this->owner)->postJson($this->api('/reservations/quote'), ['rooms' => $rooms, 'promo_code' => 'save'])->assertOk()
            ->assertJsonPath('discount_total', '2000.00')->assertJsonPath('offers.0.name', 'Promo')
            ->assertJsonPath('promo.status', 'applied')->assertJsonPath('rooms.0.discount_total', '2000.00');
        $this->actingAs($this->owner)->postJson($this->api('/reservations/quote'), ['rooms' => $rooms, 'promo_code' => 'NOPE'])->assertOk()
            ->assertJsonPath('discount_total', '0.00')->assertJsonPath('promo.status', 'unknown')->assertJsonPath('promo.message', __('offers.reasons.unknown_code'));

        // Saving with an invalid code is a field error.
        $this->actingAs($this->owner)->postJson($this->api('/reservations'), [
            'status' => 'confirmed', 'guest' => ['first_name' => 'Ann', 'last_name' => 'Lee'], 'rooms' => $rooms, 'promo_code' => 'NOPE',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['promo_code']]]);
    }
}
