<?php

namespace Tests\Feature\Billing;

use Database\Seeders\AccommodationDemoSeeder;
use Database\Seeders\BillingDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingDemoSeederTest extends TestCase
{
    public function test_demo_billing_is_consistent_and_idempotent(): void
    {
        config(['ozepms.inventory.horizon_days' => 60]);
        // Demo properties and reservations through DemoSeeder (which also runs BillingDemoSeeder).
        $this->seed(AccommodationDemoSeeder::class);
        $counts = fn () => [DB::table('folio_lines')->count(), DB::table('payments')->count(), DB::table('invoices')->count(), DB::table('services')->count()];
        $before = $counts();
        $this->assertGreaterThan(0, $before[0]);
        $this->assertGreaterThan(0, $before[1]);
        $this->assertGreaterThan(0, DB::table('invoices')->where('invoice_type', 'tax_invoice')->count());
        $this->assertSame(2, DB::table('invoices')->where('invoice_type', 'credit_note')->count());
        $this->assertSame(DB::table('reservations')->count(), DB::table('folios')->count(), 'every reservation has a folio');

        $this->seed(BillingDemoSeeder::class);
        $this->assertSame($before, $counts());
        // Folio totals match their lines and payments.
        $this->assertSame([], DB::select(<<<'SQL'
SELECT f.id FROM folios f
  LEFT JOIN (SELECT folio_id, SUM(amount) a, SUM(tax_amount) t FROM folio_lines GROUP BY folio_id) l ON l.folio_id = f.id
 WHERE f.charges_total <> COALESCE(l.a, 0) OR f.tax_total <> COALESCE(l.t, 0)
SQL));
    }
}
