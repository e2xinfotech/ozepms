<?php

namespace Tests\Feature\Billing;

use App\Models\FolioLine;
use App\Models\FolioLineTax;

/**
 * Room nights posted to the folio carry India GST by the tariff of each room-night
 * (0 % ≤ 1000, 5 % ≤ 7500, 18 % above), split into CGST + SGST that add up to the line tax.
 */
class RoomTaxPostingTest extends BillingTestCase
{
    /** @return array<string, string> tax per night tariff */
    private function postAt(array $rates): array
    {
        $rooms = [];
        foreach (array_values($rates) as $i => $rate) {
            $rooms[] = $this->room($i % 2 ? $this->steBar : $this->dlxBar, 2, 3, ['rate' => $rate]);
        }
        $r = $this->book($rooms);
        $this->within(fn () => $this->folios()->postRoomNights($r, null, $this->owner));

        return $this->lines($r)->mapWithKeys(fn (FolioLine $l) => [(string) $l->amount => (string) $l->tax_amount])->all();
    }

    public function test_slab_boundaries_per_room_night(): void
    {
        $this->assertSame(['1000.00' => '0.00', '1000.01' => '50.00'], $this->postAt(['1000.00', '1000.01']));
    }

    public function test_upper_slab_boundary(): void
    {
        $this->assertSame(['7500.00' => '375.00', '7500.01' => '1350.00'], $this->postAt(['7500.00', '7500.01']));
    }

    public function test_cgst_and_sgst_are_rounded_separately_and_add_up(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 5, ['rate' => '1234.57'])]);
        $this->within(fn () => $this->folios()->postRoomNights($r, null, $this->owner));
        foreach ($this->lines($r) as $line) {
            $taxes = FolioLineTax::query()->where('folio_line_id', $line->id)->get();
            $this->assertSame(['CGST', 'SGST'], $taxes->pluck('component')->all());
            $this->assertSame(['30.86', '30.86'], $taxes->map(fn ($t) => (string) $t->tax_amount)->all());
            $this->assertSame(['2.5000', '2.5000'], $taxes->map(fn ($t) => (string) $t->rate)->all());
            $this->assertSame('61.72', (string) $line->tax_amount, 'line tax = sum of its components');
        }
        $folio = $this->folioOf($r);
        $this->assertSame('3703.71', (string) $folio->charges_total);
        $this->assertSame('185.16', (string) $folio->tax_total, 'folio tax = sum of line taxes (3 × 61.72)');
        $this->assertSame('3888.87', $this->within(fn () => $this->folios()->summary($r))['total']);
    }

    public function test_posted_nights_match_the_reservation_price(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4), $this->room($this->steBar, 2, 3)]);
        $before = (string) $r->grand_total;
        $this->within(fn () => $this->folios()->postRoomNights($r, null, $this->owner));
        $this->assertSame($before, $this->within(fn () => $this->folios()->summary($r))['total']);
        $this->assertSame('0.00', $this->within(fn () => $this->folios()->summary($r))['pending_room_charges']);
        // 9000 / night is above 7500 → 18 %
        $suite = $this->lines($r)->firstWhere('amount', '9000.00');
        $this->assertSame('1620.00', (string) $suite->tax_amount);
    }
}
