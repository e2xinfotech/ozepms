<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Models\PaymentGatewayEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Razorpay orders, Checkout verification and webhooks with a faked HTTP client (no network). */
class RazorpayTest extends BillingTestCase
{
    private const SECRET = 'key_secret_test';

    private const HOOK = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ozepms.billing.razorpay' => array_merge(config('ozepms.billing.razorpay'), [
            'key_id' => 'rzp_test_123', 'key_secret' => self::SECRET, 'webhook_secret' => self::HOOK, 'base_url' => 'https://api.razorpay.test/v1',
        ])]);
        Http::preventStrayRequests();
    }

    private function fakeOrder(string $id = 'order_ABC'): void
    {
        Http::fake(['api.razorpay.test/v1/orders' => Http::response(['id' => $id, 'amount' => 420000, 'currency' => 'INR', 'status' => 'created'])]);
    }

    private function start(): array
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 3)]);   // 4200
        $this->fakeOrder();
        $p = $this->within(fn () => $this->payments()->startOnline($r, ['amount' => '4200.00', 'idempotency_key' => 'on1'], $this->owner));

        return [$r, $p];
    }

    private function webhook(array $payload, ?string $signature = null, string $eventId = 'evt_1')
    {
        $body = json_encode($payload);

        return $this->call('POST', '/hooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::HOOK),
            'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
        ], $body);
    }

    private function captured(string $order, string $paymentId = 'pay_XYZ', int $amount = 420000): array
    {
        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => $paymentId, 'order_id' => $order, 'amount' => $amount, 'currency' => 'INR', 'status' => 'captured']]]];
    }

    public function test_order_is_created_in_paise_and_retry_reuses_it(): void
    {
        [$r, $p] = $this->start();
        $this->assertSame('pending', $p->status);
        $this->assertSame('order_ABC', $p->gateway_order_id);
        Http::assertSent(fn (Request $req) => $req['amount'] === 420000 && $req['currency'] === 'INR' && $req->hasHeader('Authorization'));
        $again = $this->within(fn () => $this->payments()->startOnline($r, ['amount' => '4200.00', 'idempotency_key' => 'on1'], $this->owner));
        $this->assertSame($p->id, $again->id);
        Http::assertSentCount(1);
        $data = $this->within(fn () => $this->payments()->checkoutData($p, $r->load('primaryGuest')));
        $this->assertSame('rzp_test_123', $data['key']);
        $this->assertSame(420000, $data['amount']);
        $this->assertSame('0.00', $this->within(fn () => $this->folios()->summary($r))['paid'], 'pending payments do not count');
    }

    public function test_gateway_failure_marks_payment_failed_and_retry_works(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 3)]);
        Http::fake(['api.razorpay.test/v1/orders' => Http::sequence()->push(['error' => ['description' => 'down']], 500)->push(['id' => 'order_2'])]);
        try {
            $this->within(fn () => $this->payments()->startOnline($r, ['amount' => '100', 'idempotency_key' => 'x'], $this->owner));
            $this->fail('no error');
        } catch (ValidationException) {
        }
        $this->assertSame('failed', Payment::acrossProperties()->first()->status);
        $p = $this->within(fn () => $this->payments()->startOnline($r, ['amount' => '100', 'idempotency_key' => 'x'], $this->owner));
        $this->assertSame('order_2', $p->gateway_order_id);
        $this->assertSame('pending', $p->status);
        $this->assertSame(1, Payment::acrossProperties()->count());
    }

    public function test_checkout_signature_valid_and_invalid(): void
    {
        [$r, $p] = $this->start();
        try {
            $this->within(fn () => $this->payments()->verifyOnline($p, 'order_ABC', 'pay_1', 'bad', $this->owner));
            $this->fail('bad signature accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('signature', $e->errors());
        }
        $sig = hash_hmac('sha256', 'order_ABC|pay_1', self::SECRET);
        $paid = $this->within(fn () => $this->payments()->verifyOnline($p, 'order_ABC', 'pay_1', $sig, $this->owner));
        $this->assertSame('captured', $paid->status);
        $this->assertSame('pay_1', $paid->gateway_payment_id);
        $this->assertSame('4200.00', $this->within(fn () => $this->folios()->summary($r))['paid']);
    }

    public function test_webhook_valid_signature_captures_once_and_replay_is_ignored(): void
    {
        [$r, $p] = $this->start();
        $this->webhook($this->captured('order_ABC'))->assertOk()->assertJson(['status' => 'ok']);
        $this->assertSame('captured', $p->fresh()->status);
        $this->assertSame('pay_XYZ', $p->fresh()->gateway_payment_id);

        $this->webhook($this->captured('order_ABC'))->assertOk()->assertJson(['status' => 'duplicate']);
        // Same payment under another event id (e.g. order.paid): still one capture.
        $this->webhook(['event' => 'order.paid'] + $this->captured('order_ABC'), null, 'evt_2')->assertOk();
        $this->assertSame(2, PaymentGatewayEvent::query()->where('signature_valid', true)->count());
        $this->assertSame(1, Payment::acrossProperties()->where('status', 'captured')->count());
        $this->assertSame('4200.00', $this->within(fn () => $this->folios()->summary($r))['paid']);
        $this->assertSame($this->property->id, PaymentGatewayEvent::query()->where('event_id', 'evt_1')->value('property_id'));
    }

    public function test_webhook_with_invalid_signature_is_refused_and_logged(): void
    {
        [, $p] = $this->start();
        $this->webhook($this->captured('order_ABC'), 'forged')->assertStatus(400);
        $this->assertSame('pending', $p->fresh()->status);
        $event = PaymentGatewayEvent::query()->firstOrFail();
        $this->assertFalse($event->signature_valid);
        $this->assertStringStartsWith('invalid:', $event->event_id);
        // The genuine event with the same id is still processed afterwards.
        $this->webhook($this->captured('order_ABC'))->assertOk()->assertJson(['status' => 'ok']);
        $this->assertSame('captured', $p->fresh()->status);
    }

    public function test_webhook_amount_mismatch_is_not_captured(): void
    {
        [, $p] = $this->start();
        $this->webhook($this->captured('order_ABC', 'pay_1', 100))->assertOk()->assertJson(['status' => 'amount_mismatch']);
        $this->assertSame('pending', $p->fresh()->status);
        $this->assertSame('amount_mismatch', PaymentGatewayEvent::query()->value('error'));
    }

    public function test_payment_failed_and_gateway_refund_flow(): void
    {
        [$r, $p] = $this->start();
        $this->webhook($this->captured('order_ABC'))->assertOk();
        Http::fake(['api.razorpay.test/v1/payments/pay_XYZ/refund' => Http::response(['id' => 'rfnd_1', 'status' => 'pending'])]);
        $refund = $this->within(fn () => $this->payments()->refund($p->fresh(), ['amount' => '1000', 'notes' => 'Goodwill', 'idempotency_key' => 'rf'], $this->owner));
        $this->assertSame('pending', $refund->status);
        $this->assertSame('rfnd_1', $refund->gateway_payment_id);
        $this->assertSame('4200.00', $this->within(fn () => $this->folios()->summary($r))['paid'], 'pending refund not counted yet');

        $this->webhook(['event' => 'refund.processed', 'payload' => ['refund' => ['entity' => ['id' => 'rfnd_1', 'payment_id' => 'pay_XYZ', 'amount' => 100000]]]], null, 'evt_r')->assertOk();
        $this->assertSame('captured', $refund->fresh()->status);
        $this->assertSame('3200.00', $this->within(fn () => $this->folios()->summary($r))['paid']);
    }

    public function test_disabled_without_keys(): void
    {
        config(['ozepms.billing.razorpay.key_secret' => null]);
        $r = $this->book([$this->room($this->dlxBar, 2, 3)]);
        $this->assertFalse($this->payments()->onlineEnabled());
        try {
            $this->within(fn () => $this->payments()->startOnline($r, ['amount' => '100', 'idempotency_key' => 'x'], $this->owner));
            $this->fail('started while disabled');
        } catch (ValidationException) {
        }
        $this->webhook($this->captured('order_ABC'))->assertStatus(400);
        Http::assertNothingSent();
    }
}
