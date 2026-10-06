<?php

namespace Tests\Feature\BookingEngine;

use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** Advance payment set by the hotel: the guest pays online (Razorpay, faked) and the booking is confirmed. */
class BookingOnlinePaymentTest extends BookingEngineTestCase
{
    private const SECRET = 'test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ozepms.billing.razorpay' => array_merge(config('ozepms.billing.razorpay'), [
            'key_id' => 'rzp_test_123', 'key_secret' => self::SECRET, 'webhook_secret' => 'hook', 'base_url' => 'https://api.razorpay.test/v1',
        ])]);
        Http::preventStrayRequests();
        Http::fake(['api.razorpay.test/v1/orders' => Http::response(['id' => 'order_BE1', 'status' => 'created'])]);
        Notification::fake();
    }

    public function test_deposit_is_paid_online_and_confirms_the_booking(): void
    {
        DB::table('rate_plans')->where('id', $this->bar()->id)->update(['payment_type' => 'deposit_percent', 'deposit_value' => 25]);
        $base = '/book/'.$this->property->code;
        $search = $this->getJson($base.'/api/search?'.http_build_query($this->stayQuery()))->json();
        $room = collect($search['room_types'])->firstWhere('name', 'Deluxe Room');
        $rate = $room['rates'][0];
        $this->assertSame(\App\Support\Money::round(\App\Support\Money::percent($rate['grand_total'], '25')), $rate['pay_now']);

        $res = $this->postJson($base.'/api/book', array_merge($this->stayQuery(), [
            'room_type_id' => $room['id'], 'rate_plan_id' => $rate['rate_plan_id'], 'quoted_total' => $rate['grand_total'],
            'idempotency_key' => 'pay-1', 'guest' => $this->guest(), 'accept_terms' => true,
        ]))->assertCreated()->assertJsonPath('booking.status', 'pending')->assertJsonPath('payment.checkout.order_id', 'order_BE1');
        $r = Reservation::acrossProperties()->firstOrFail();
        $this->assertNotNull($r->hold_expires_at, 'rooms held while the guest pays');
        $this->assertSame($rate['pay_now'], (string) DB::table('payments')->where('reservation_id', $r->id)->value('amount'));
        Notification::assertNothingSent();

        $pid = $res->json('payment.id');
        $this->postJson($base."/api/payments/$pid/checkout")->assertOk()->assertJsonPath('payment.checkout.order_id', 'order_BE1');
        $this->postJson($base."/api/payments/$pid/verify", ['razorpay_order_id' => 'order_BE1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'forged'])->assertStatus(422);
        $this->assertSame('pending', $r->fresh()->status);

        $sig = hash_hmac('sha256', 'order_BE1|pay_1', self::SECRET);
        $this->postJson($base."/api/payments/$pid/verify", ['razorpay_order_id' => 'order_BE1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $sig])
            ->assertOk()->assertJsonPath('booking.status', 'confirmed');
        $this->assertNull($r->fresh()->hold_expires_at);
        Notification::assertSentOnDemandTimes(\App\Notifications\BookingReceivedNotification::class, 1);
        $this->get($res->json('confirmation_url'))->assertOk()->assertSee($r->booking_ref);
    }
}
