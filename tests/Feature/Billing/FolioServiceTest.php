<?php

namespace Tests\Feature\Billing;

use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationNoShow;
use App\Models\FolioLine;
use App\Models\Invoice;
use App\Models\Reservation;
use Illuminate\Validation\ValidationException;

class FolioServiceTest extends BillingTestCase
{
    public function test_booking_opens_folio_with_pending_room_charges(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 5)]);
        $folio = $this->folioOf($r);
        $this->assertMatchesRegularExpression('/^F-\d{6}$/', $folio->folio_no);
        $s = $this->within(fn () => $this->folios()->summary($r));
        // 3 × 4000 + 5 % GST (slab 1000.01–7500)
        $this->assertSame('12600.00', $s['total']);
        $this->assertSame('12600.00', $s['balance']);
        $this->assertSame('0.00', $s['paid']);
        $this->assertSame('12600.00', $s['pending_room_charges']);
        $this->assertSame($s['total'], $s['charges']);
        $this->assertSame($s['paid'], $s['payments']);
        $this->assertSame((string) $r->fresh()->grand_total, $s['total']);
    }

    public function test_room_nights_are_posted_once_with_cgst_sgst(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 0, 3)]);
        $n = $this->within(fn () => $this->folios()->postRoomNights($r, $this->day(1), $this->owner));
        $this->assertSame(2, $n);
        $this->assertSame(0, $this->within(fn () => $this->folios()->postRoomNights($r, $this->day(1), $this->owner)));
        $lines = $this->lines($r);
        $this->assertCount(2, $lines);
        $this->assertSame('room', $lines[0]->line_type);
        $this->assertSame('4000.00', (string) $lines[0]->amount);
        $this->assertSame('200.00', (string) $lines[0]->tax_amount);
        $this->assertSame('9963', $lines[0]->sac_hsn_code);
        $this->assertSame($this->day(0)->toDateString(), $lines[0]->business_date->toDateString());
        $this->assertSame(['CGST', 'SGST'], $lines[0]->taxes()->pluck('component')->all());
        $s = $this->within(fn () => $this->folios()->summary($r));
        $this->assertSame('12600.00', $s['total']);
        $this->assertSame('8400.00', $s['posted']);
        $this->assertSame('4200.00', $s['pending_room_charges']);
        $this->assertSame(2, $s['lines_count']);
        $this->assertSame('12600.00', (string) Reservation::acrossProperties()->find($r->id)->grand_total);
    }

    public function test_extras_adjustments_and_discounts(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)]);   // 8000 + 400 GST
        $bed = $this->makeService();
        $this->within(function () use ($r, $bed) {
            // Extra bed for 2 nights, "Other services" category (18 % GST template applies to add-ons? none → 0)
            $this->folios()->postCharge($r, ['type' => 'service', 'service' => $bed, 'quantity' => '2', 'idempotency_key' => 'k1'], $this->owner);
            $this->folios()->postCharge($r, ['type' => 'adjustment', 'description' => 'Minibar correction', 'unit_price' => '-100', 'tax_category_id' => null, 'idempotency_key' => 'k2'], $this->owner);
            // 10 % of the room charges (8000) on accommodation → −800 taxable, −40 GST at the stay's 5 % slab
            $this->folios()->postCharge($r, ['type' => 'discount', 'discount_percent' => '10', 'tax_category_id' => \App\Models\TaxCategory::query()->where('code', 'accommodation')->value('id'), 'idempotency_key' => 'k3'], $this->owner);
        });
        $lines = $this->lines($r)->keyBy('line_type');
        $this->assertSame('3000.00', (string) $lines['service']->amount);
        $this->assertSame('Extra bed', $lines['service']->description);
        $this->assertSame('-100.00', (string) $lines['adjustment']->amount);
        $this->assertSame('0.00', (string) $lines['adjustment']->tax_amount);
        $this->assertSame('-800.00', (string) $lines['discount']->amount);
        $this->assertSame('-40.00', (string) $lines['discount']->tax_amount);

        $fresh = Reservation::acrossProperties()->find($r->id);
        $serviceTotal = bcadd((string) $lines['service']->amount, (string) $lines['service']->tax_amount, 2);
        $this->assertSame(bcadd($serviceTotal, '-100.00', 2), (string) $fresh->extras_total);
        $this->assertSame('840.00', (string) $fresh->discount_total);
        $expected = bcsub(bcadd('8400.00', bcadd($serviceTotal, '-100.00', 2), 2), '840.00', 2);
        $this->assertSame($expected, (string) $fresh->grand_total);
        $this->assertSame($expected, $this->within(fn () => $this->folios()->summary($r))['total']);
    }

    public function test_charge_with_the_same_idempotency_key_is_posted_once(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)]);
        $a = $this->within(fn () => $this->folios()->postCharge($r, ['type' => 'adjustment', 'description' => 'Late check-out', 'unit_price' => '500', 'idempotency_key' => 'same'], $this->owner));
        $b = $this->within(fn () => $this->folios()->postCharge($r, ['type' => 'adjustment', 'description' => 'Late check-out', 'unit_price' => '500', 'idempotency_key' => 'same'], $this->owner));
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, FolioLine::acrossProperties()->where('line_type', 'adjustment')->count());
    }

    public function test_void_posts_a_reversal_and_restores_totals(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)]);
        $line = $this->within(fn () => $this->folios()->postCharge($r, ['type' => 'adjustment', 'description' => 'Laundry', 'unit_price' => '300', 'idempotency_key' => 'l'], $this->owner));
        $this->assertSame('8700.00', $this->within(fn () => $this->folios()->summary($r))['total']);

        $reversal = $this->within(fn () => $this->folios()->voidLine($line, 'Posted twice', $this->owner));
        $this->assertSame('-300.00', (string) $reversal->amount);
        $this->assertSame($line->id, $reversal->void_of_line_id);
        $this->assertTrue($line->fresh()->is_void);
        $this->assertSame('8400.00', $this->within(fn () => $this->folios()->summary($r))['total']);
        $this->assertSame(2, FolioLine::acrossProperties()->count(), 'lines are never deleted');

        $this->expectException(ValidationException::class);
        $this->within(fn () => $this->folios()->voidLine($line->fresh(), 'again', $this->owner));
    }

    public function test_room_lines_cannot_be_voided_by_hand_and_fees_need_override(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 0, 2)]);
        $this->within(fn () => $this->folios()->postRoomNights($r, null, $this->owner));
        try {
            $this->within(fn () => $this->folios()->voidLine($this->lines($r)->first(), 'no', $this->owner, true));
            $this->fail('room line voided');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('line', $e->errors());
        }
        $fee = $this->within(fn () => $this->folios()->postFee($r, '1000.00', 'cancellation', $this->owner));
        try {
            $this->within(fn () => $this->folios()->voidLine($fee, 'waive', $this->owner, false));
            $this->fail('fee voided without override');
        } catch (ValidationException) {
        }
        $this->within(fn () => $this->folios()->voidLine($fee, 'waive', $this->owner, true));
        $this->assertTrue($fee->fresh()->is_void);
    }

    public function test_shortened_stay_voids_posted_nights_that_left_the_stay(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 5)]);
        $this->within(fn () => $this->folios()->postRoomNights($r, null, $this->owner));
        $this->assertCount(3, $this->lines($r));
        $room = $r->rooms()->first();
        $this->within(fn () => $this->service()->modify($r->fresh(), ['rooms' => [array_merge($this->room($this->dlxBar, 2, 4), ['id' => $room->id])]], $this->owner));

        $lines = $this->lines($r);
        $this->assertCount(4, $lines, '3 nights + 1 reversal');
        $this->assertSame(1, $lines->where('is_void', true)->count());
        $this->assertSame($this->day(4)->toDateString(), substr($lines->firstWhere('is_void', true)->posting_key, -10));
        $this->assertSame('8400.00', $this->within(fn () => $this->folios()->summary($r))['total']);
        $this->assertSame('8400.00', (string) Reservation::acrossProperties()->find($r->id)->grand_total);
    }

    public function test_cancellation_fee_is_posted_and_pending_nights_disappear(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 5)]);
        $r = $this->within(fn () => $this->service()->cancel($r, 'Plans changed', $this->owner))->fresh();
        $this->assertSame('0.00', $this->within(fn () => $this->folios()->summary($r))['total'], 'free cancellation under BAR');

        event(new ReservationCancelled($r, $this->owner, '4000.00', 'Plans changed'));
        event(new ReservationCancelled($r, $this->owner, '4000.00', 'Plans changed'));   // delivered twice → posted once
        $fees = $this->lines($r)->where('line_type', 'cancellation_fee');
        $this->assertCount(1, $fees);
        $this->assertSame('Cancellation fee', $fees->first()->description);
        $s = $this->within(fn () => $this->folios()->summary($r));
        $this->assertSame('4000.00', $s['total']);
        $this->assertSame('4000.00', (string) Reservation::acrossProperties()->find($r->id)->grand_total);
    }

    public function test_no_show_fee_event(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 0, 2)]);
        event(new ReservationNoShow($r, null, '4200.00'));
        $this->assertSame('No-show fee', $this->lines($r)->firstWhere('line_type', 'cancellation_fee')->description);
    }

    public function test_check_out_posts_remaining_nights_issues_invoice_and_guard(): void
    {
        $r = $this->inHouse(1);   // one night: 4000 + 200
        $this->assertFalse($this->within(fn () => $this->folios()->canCheckOut($r, null)));
        $this->assertFalse($this->asMember($frontDesk = $this->member('front_desk'), fn () => $this->folios()->canCheckOut($r, $frontDesk)));
        $this->assertTrue($this->asMember($accounts = $this->member('accounts'), fn () => $this->folios()->canCheckOut($r, $accounts)), 'accounts hold billing.override');
        $this->within(fn () => $this->payments()->record($r, ['method' => 'cash', 'amount' => '4200.00', 'idempotency_key' => 'p1'], $this->owner));
        $this->assertTrue($this->within(fn () => $this->folios()->canCheckOut($r, null)));

        $this->within(fn () => $this->service()->checkOut($r->fresh(), null, $this->owner));
        $this->assertCount(1, $this->lines($r)->where('line_type', 'room'));
        $invoice = Invoice::acrossProperties()->firstOrFail();
        $this->assertSame('tax_invoice', $invoice->invoice_type);
        $this->assertSame('4200.00', (string) $invoice->grand_total);
        $this->assertSame('closed', $this->folioOf($r)->status);
        $this->assertSame('paid', Reservation::acrossProperties()->find($r->id)->payment_status);
    }

    public function test_check_out_with_open_balance_is_refused_by_reservations(): void
    {
        $r = $this->inHouse(1);
        $this->expectException(ValidationException::class);
        $this->within(fn () => $this->service()->checkOut($r, null, $this->owner));
    }
}
