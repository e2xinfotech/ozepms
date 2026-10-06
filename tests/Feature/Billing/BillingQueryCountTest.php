<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Folio, payments, invoices and services must not grow with the number of rows (no N+1).
 * Counts include the request's own middleware queries (session user, property, membership, permissions).
 */
class BillingQueryCountTest extends BillingTestCase
{
    private const MAX = 20;

    private function queries(callable $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $n = count(DB::getQueryLog());
        if (getenv('SHOW_QUERIES')) {
            fwrite(STDERR, implode("\n", array_map(fn ($q) => substr($q['query'], 0, 160), DB::getQueryLog()))."\n----\n");
        }
        DB::disableQueryLog();

        return $n;
    }

    private function stay(int $extras, int $from)
    {
        $r = $this->book([$this->room($this->dlxBar, $from, $from + 4), $this->room($this->steBar, $from, $from + 2)]);
        $this->within(function () use ($r, $extras, $from) {
            $this->folios()->postRoomNights($r, $this->day(1), $this->owner);
            $this->folios()->postRoomNights($r, $this->day(6), $this->owner);
            for ($i = 0; $i < $extras; $i++) {
                $this->folios()->postCharge($r, ['type' => 'adjustment', 'description' => 'Item '.$i, 'unit_price' => '100', 'idempotency_key' => 'x'.$i], $this->owner);
                $this->payments()->record($r, ['method' => 'cash', 'amount' => '50', 'idempotency_key' => 'p'.$from.'-'.$i], $this->owner);
            }
            $this->invoices()->issue($this->folios()->open($r), [], $this->owner);
        });

        return $r;
    }

    public function test_query_counts_stay_small_and_constant(): void
    {
        $small = $this->stay(1, 0);
        $large = $this->stay(8, 4);
        for ($i = 0; $i < 12; $i++) {
            $this->makeService(['code' => 'S'.$i]);
        }
        $this->actingAs($this->owner);
        $invoice = DB::table('invoices')->where('folio_id', $this->folioOf($large)->id)->value('public_id');

        foreach ([
            'folio' => fn ($r) => $this->getJson($this->api("/reservations/{$r->public_id}/folio"))->assertOk(),
            'payments' => fn ($r) => $this->getJson($this->api("/reservations/{$r->public_id}/payments"))->assertOk(),
            'invoices' => fn ($r) => $this->getJson($this->api("/reservations/{$r->public_id}/invoices"))->assertOk(),
            'options' => fn ($r) => $this->getJson($this->api("/billing/options?reservation={$r->public_id}"))->assertOk(),
        ] as $name => $call) {
            $call($small);   // warm-up (session, permission cache)
            $a = $this->queries(fn () => $call($small));
            $b = $this->queries(fn () => $call($large));
            $this->assertLessThanOrEqual(self::MAX, $b, "$name: $b queries");
            $this->assertSame($a, $b, "$name grows with rows ($a → $b)");
        }
        $this->assertLessThanOrEqual(self::MAX, $this->queries(fn () => $this->getJson($this->api('/services'))->assertOk()), 'services list');
        $this->assertLessThanOrEqual(self::MAX + 6, $this->queries(fn () => $this->get($this->page('/services'))->assertOk()), 'services page (with shell)');
        $this->assertLessThanOrEqual(self::MAX + 6, $this->queries(fn () => $this->get($this->page('/invoices/'.$invoice))->assertOk()), 'invoice page');
        $this->assertLessThanOrEqual(self::MAX + 10, $this->queries(fn () => $this->get($this->page("/reservations/{$large->public_id}/folio"))->assertOk()), 'folio page');
    }
}
