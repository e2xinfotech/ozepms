<?php

namespace Tests\Feature\Billing;

use App\Models\Reservation;

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
        $this->assertSame('4000.00', (string) $lines[0]->amount);
        $this->assertSame('200.00', (string) $lines[0]->tax_amount);
        $this->assertSame('9963', $lines[0]->sac_hsn_code);
        $this->assertSame(['CGST', 'SGST'], $lines[0]->taxes()->pluck('component')->all());
        $s = $this->within(fn () => $this->folios()->summary($r));
        $this->assertSame('12600.00', $s['total']);
        $this->assertSame('8400.00', $s['posted']);
        $this->assertSame('4200.00', $s['pending_room_charges']);
        $this->assertSame('12600.00', (string) Reservation::acrossProperties()->find($r->id)->grand_total);
    }
}
