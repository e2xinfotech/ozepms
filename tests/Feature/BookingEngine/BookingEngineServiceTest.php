<?php

namespace Tests\Feature\BookingEngine;

use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingEngineServiceTest extends BookingEngineTestCase
{
    public function test_open_only_for_active_properties_on_a_plan_with_the_booking_engine(): void
    {
        $this->assertNotNull($this->engine()->property(strtolower($this->property->code)));

        $this->engine()->saveSettings($this->property, ['enabled' => false]);
        $this->assertNull($this->engine()->property($this->property->code), 'switched off by the property');
        $this->engine()->saveSettings($this->property, ['enabled' => true]);

        DB::table('subscriptions')->where('property_id', $this->property->id)->update(['plan_id' => DB::table('subscription_plans')->where('code', 'starter')->value('id')]);
        $this->assertNull($this->engine()->property($this->property->code), 'plan without the booking engine');
        DB::table('subscriptions')->where('property_id', $this->property->id)->update(['plan_id' => DB::table('subscription_plans')->where('code', 'professional')->value('id')]);

        DB::table('properties')->where('id', $this->property->id)->update(['status' => 'suspended']);
        $this->assertNull($this->engine()->property($this->property->code));
        $this->assertNull($this->engine()->property('P9999'));
    }

    public function test_search_lists_bookable_room_types_cheapest_first_with_offers_and_taxes(): void
    {
        $this->offer(['name' => 'Web Deal', 'discount_value' => '10', 'on_pms' => false]);
        $r = $this->engine()->search($this->property, $this->stayQuery());
        $this->assertSame(2, $r['nights']);
        $this->assertSame(['Deluxe Room', 'Suite'], array_column($r['room_types'], 'name'));
        $rate = $r['room_types'][0]['rates'][0];
        $this->assertSame('8000.00', $rate['price_before']);
        $this->assertSame('800.00', $rate['discount']);
        $this->assertSame('7200.00', $rate['room_total']);
        $this->assertSame([['name' => 'Web Deal', 'promo_code' => null]], $rate['offers']);
        $this->assertTrue((float) $rate['taxes'] > 0);
        $this->assertArrayNotHasKey('product_id', $rate, 'no internal ids');

        // Products not sold on the booking engine are left out; too many guests → no room types.
        DB::table('rate_plans')->where('id', $this->bar()->id)->update(['sell_on_booking_engine' => 0]);
        DB::table('properties')->where('id', $this->property->id)->increment('ari_version');
        $this->assertSame([], $this->engine()->search($this->property, $this->stayQuery())['room_types']);
    }

    public function test_search_validation(): void
    {
        foreach ([[-1, 2, 'check_in'], [5, 5, 'check_out'], [5, 60, 'check_out'], [600, 602, 'check_in']] as [$in, $out, $field]) {
            try {
                $this->engine()->search($this->property, $this->stayQuery($in, $out));
                $this->fail("$in-$out accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_book_pay_at_property_is_confirmed_and_goes_through_the_reservation_engine(): void
    {
        $search = $this->engine()->search($this->property, $this->stayQuery(5, 7, ['rooms' => 2]));
        $rate = $search['room_types'][0]['rates'][0];
        $res = $this->engine()->book($this->property, $this->stayQuery(5, 7, [
            'rooms' => 2, 'room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $this->bar()->public_id,
            'guest' => $this->guest(), 'quoted_total' => $rate['grand_total'], 'idempotency_key' => 'k-1', 'special_requests' => 'Late arrival',
        ]));
        $r = $res['reservation'];
        $this->assertSame('confirmed', $r->status);
        $this->assertSame(2, (int) $r->room_count);
        $this->assertSame($rate['grand_total'], (string) $r->grand_total);
        $this->assertSame('booking_engine', DB::table('booking_sources')->where('id', $r->source_id)->value('code'));
        $this->assertNull($res['payment']);
        $this->assertSame([2, 2], $this->sold($this->deluxe, 5, 7));
        $this->assertInventoryConsistent();

        // Same key again: the same booking, no second one.
        $again = $this->engine()->book($this->property, $this->stayQuery(5, 7, ['rooms' => 2, 'room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $this->bar()->public_id, 'guest' => $this->guest(), 'idempotency_key' => 'k-1']));
        $this->assertSame($r->id, $again['reservation']->id);
        $this->assertSame(1, Reservation::acrossProperties()->count());

        // Only one deluxe room is left: two rooms cannot be booked.
        try {
            $this->engine()->book($this->property, $this->stayQuery(5, 7, ['rooms' => 2, 'room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $this->bar()->public_id, 'guest' => $this->guest()]));
            $this->fail('overbooked');
        } catch (ValidationException) {
        }
    }

    public function test_prepaid_rate_without_online_payment_stays_pending_and_deposit_amounts(): void
    {
        $plan = $this->bar();
        DB::table('rate_plans')->where('id', $plan->id)->update(['payment_type' => 'deposit_percent', 'deposit_value' => 30]);
        $res = $this->engine()->book($this->property, $this->stayQuery(5, 7, ['room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $plan->public_id, 'guest' => $this->guest()]));
        $this->assertSame('pending', $res['reservation']->status);
        $this->assertNull($res['reservation']->hold_expires_at, 'no online payment set up: the property follows up, no expiry');
        $this->assertSame(\App\Support\Money::round(\App\Support\Money::percent((string) $res['reservation']->grand_total, '30')), $res['due']);
    }

    public function test_rate_not_sold_online_cannot_be_booked(): void
    {
        $hidden = $this->plan('CORP', ['sell_on_booking_engine' => 0]);
        $this->product($this->deluxe, $hidden, ['default_price' => '3000.00']);
        try {
            $this->engine()->book($this->property, $this->stayQuery(5, 7, ['room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $hidden->public_id, 'guest' => $this->guest()]));
            $this->fail('booked a rate that is not online');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rate_plan_id', $e->errors());
        }
    }
}
