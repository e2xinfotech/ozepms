<?php

namespace Tests\Feature\Reservations;

use App\Domain\Billing\FolioService;
use App\Models\ReservationRoom;
use Illuminate\Validation\ValidationException;

/** Leaving early: only the nights stayed are owed at check-out, and the folio ends at zero. */
class EarlyCheckOutBalanceTest extends ReservationTestCase
{
    public function test_early_check_out_asks_only_for_the_nights_stayed(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 0, 3)]);
        $this->inProperty($this->property);
        $room = ReservationRoom::acrossProperties()->where('reservation_id', $r->id)->first();
        $this->service()->assignUnit($r, $room, $this->units($this->deluxe)->first(), $this->owner);
        $this->service()->checkIn($r->fresh(), null, $this->owner);

        $folios = app(FolioService::class);
        $today = $this->day(0)->toDateString();
        $full = $folios->summary($r->fresh())['balance'];
        $atCheckout = $folios->checkoutBalance($r->fresh(), $today);
        // Three nights booked, leaving after the first: two nights are not charged.
        $this->assertSame(0, bccomp($atCheckout, bcdiv($full, '3', 2), 0), "full {$full}, at check-out {$atCheckout}");
        $this->assertSame($atCheckout, $this->service()->balance($r->fresh(), $today));

        try {
            $this->service()->checkOut($r->fresh(), null, $this->owner);
            $this->fail('Checked out with an open balance');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('balance', $e->errors());
        }

        $this->payBalance($r);
        $this->service()->checkOut($r->fresh(), null, $this->owner);
        $r->refresh();
        $this->assertSame('checked_out', $r->status);
        $this->assertSame(1, (int) $r->nights);
        $this->assertSame('0.00', $folios->summary($r)['balance'], 'nothing owed, nothing to refund');
        $this->assertInventoryConsistent();
    }
}
