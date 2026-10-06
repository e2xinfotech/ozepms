<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Validation\ValidationException;

class PaymentServiceTest extends BillingTestCase
{
    public function test_payment_updates_folio_and_reservation(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)]);   // 8400
        $p = $this->within(fn () => $this->payments()->record($r, ['method' => 'upi', 'amount' => '3000', 'reference' => 'UTR123', 'idempotency_key' => 'a'], $this->owner));
        $this->assertSame('captured', $p->status);
        $this->assertTrue($p->is_deposit, 'paid before arrival → deposit');
        $this->assertSame('INR', $p->currency_code);
        $s = $this->within(fn () => $this->folios()->summary($r));
        $this->assertSame('3000.00', $s['paid']);
        $this->assertSame('5400.00', $s['balance']);
        $fresh = Reservation::acrossProperties()->find($r->id);
        $this->assertSame('partial', $fresh->payment_status);
        $this->assertSame('3000.00', (string) $fresh->paid_total);
        $this->assertSame('5400.00', (string) $fresh->balance_due);
    }

    public function test_double_submit_records_one_payment(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)]);
        $svc = $this->payments();
        $a = $this->within(fn () => $svc->record($r, ['method' => 'cash', 'amount' => '1000', 'idempotency_key' => 'dbl'], $this->owner));
        $this->assertFalse($svc->replayed);
        $b = $this->within(fn () => $svc->record($r, ['method' => 'cash', 'amount' => '1000', 'idempotency_key' => 'dbl'], $this->owner));
        $this->assertTrue($svc->replayed);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Payment::acrossProperties()->count());
        $this->assertSame('1000.00', $this->within(fn () => $this->folios()->summary($r))['paid']);
    }

    public function test_validation_of_method_and_amount(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)]);
        foreach ([['method' => 'gateway', 'amount' => '10'], ['method' => 'cash', 'amount' => '0'], ['method' => 'cash', 'amount' => '-5'], ['method' => 'cash', 'amount' => 'abc']] as $data) {
            try {
                $this->within(fn () => $this->payments()->record($r, $data + ['idempotency_key' => uniqid()], $this->owner));
                $this->fail('accepted '.json_encode($data));
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, Payment::acrossProperties()->count());
    }

    public function test_refunds_partial_then_full_and_never_more_than_paid(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)]);
        $p = $this->within(fn () => $this->payments()->record($r, ['method' => 'card', 'amount' => '5000', 'idempotency_key' => 'p'], $this->owner));
        $refund = $this->within(fn () => $this->payments()->refund($p, ['amount' => '2000', 'notes' => 'Early departure', 'idempotency_key' => 'r1'], $this->owner));
        $this->assertSame('refund', $refund->kind);
        $this->assertSame('card', $refund->method);
        $this->assertSame('partially_refunded', $p->fresh()->status);
        $this->assertSame('3000.00', $this->within(fn () => $this->folios()->summary($r))['paid']);

        try {
            $this->within(fn () => $this->payments()->refund($p->fresh(), ['amount' => '3000.01', 'notes' => 'x', 'idempotency_key' => 'r2'], $this->owner));
            $this->fail('over-refund');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
        // Retried refund with the same key: one refund.
        $this->within(fn () => $this->payments()->refund($p->fresh(), ['amount' => '2000', 'notes' => 'Early departure', 'idempotency_key' => 'r1'], $this->owner));
        $this->assertSame(1, Payment::acrossProperties()->where('kind', 'refund')->count());

        $this->within(fn () => $this->payments()->refund($p->fresh(), ['amount' => '3000', 'notes' => 'Cancelled', 'method' => 'cash', 'idempotency_key' => 'r3'], $this->owner));
        $this->assertSame('refunded', $p->fresh()->status);
        $s = $this->within(fn () => $this->folios()->summary($r));
        $this->assertSame('0.00', $s['paid']);
        $this->assertSame('refunded', Reservation::acrossProperties()->find($r->id)->payment_status);

        $this->expectException(ValidationException::class);
        $this->within(fn () => $this->payments()->refund($p->fresh(), ['amount' => '1', 'notes' => 'x', 'idempotency_key' => 'r4'], $this->owner));
    }

    public function test_received_date_cannot_be_in_the_future(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)]);
        $this->expectException(ValidationException::class);
        $this->within(fn () => $this->payments()->record($r, ['method' => 'cash', 'amount' => '10', 'received_at' => $this->day(3)->toDateString(), 'idempotency_key' => 'f'], $this->owner));
    }
}
