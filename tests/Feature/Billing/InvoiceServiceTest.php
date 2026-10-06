<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\BillingNumbers;
use App\Models\FolioLine;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceServiceTest extends BillingTestCase
{
    private function posted(int $in = 0, int $out = 2)
    {
        $r = $this->book([$this->room($this->dlxBar, $in, $out)]);
        $this->within(fn () => $this->folios()->postRoomNights($r, null, $this->owner));

        return $r;
    }

    public function test_tax_invoice_numbering_and_gst_fields(): void
    {
        $r = $this->posted();
        $inv = $this->within(fn () => $this->invoices()->issue($this->folios()->open($r), [], $this->owner));
        $fy = BillingNumbers::financialYear(CarbonImmutable::parse(now('Asia/Kolkata')->toDateString()));
        $short = substr($fy, 2, 2).substr($fy, -2);
        $this->assertSame($this->property->code.'/'.$short.'/00001', $inv->invoice_no);
        $this->assertLessThanOrEqual(16, strlen($inv->invoice_no));
        $this->assertSame($fy, $inv->financial_year);
        $this->assertSame('27AABCS1234F1Z5', $inv->supplier_tax_no);
        $this->assertSame('27', $inv->supplier_state_code);
        $this->assertSame('27', $inv->place_of_supply);
        $this->assertSame('8000.00', (string) $inv->taxable_total);
        $this->assertSame('200.00', (string) $inv->cgst_total);
        $this->assertSame('200.00', (string) $inv->sgst_total);
        $this->assertSame('0.00', (string) $inv->igst_total);
        $this->assertSame('8400.00', (string) $inv->grand_total);
        $snap = $inv->snapshot;
        $this->assertSame('9963', $snap['lines'][0]['sac']);
        $this->assertSame('John Smith', $snap['parties']['bill_to']['name']);
        $this->assertSame('Rupees Eight Thousand Four Hundred Only', $snap['amount_in_words']);
        $this->assertSame([['component' => 'CGST', 'name' => 'CGST', 'rate' => '2.50', 'fixed' => false, 'taxable' => '8000.00', 'amount' => '200.00'], ['component' => 'SGST', 'name' => 'SGST', 'rate' => '2.50', 'fixed' => false, 'taxable' => '8000.00', 'amount' => '200.00']], $snap['tax_summary']);
        $this->assertSame(2, FolioLine::acrossProperties()->where('invoice_id', $inv->id)->count());

        // Nothing left → no second invoice; new charge → next number.
        try {
            $this->within(fn () => $this->invoices()->issue($this->folios()->open($r), [], $this->owner));
            $this->fail('empty invoice');
        } catch (ValidationException) {
        }
        $this->within(fn () => $this->folios()->postCharge($r, ['type' => 'adjustment', 'description' => 'Minibar', 'unit_price' => '250', 'idempotency_key' => 'm'], $this->owner));
        $second = $this->within(fn () => $this->invoices()->issue($this->folios()->open($r), ['name' => 'Acme Pvt Ltd', 'tax_no' => '27AAACA1234A1Z5'], $this->owner));
        $this->assertStringEndsWith('/00002', $second->invoice_no);
        $this->assertSame('Acme Pvt Ltd', $second->bill_to_name);
        $this->assertSame('27AAACA1234A1Z5', $second->bill_to_tax_no);
        $this->assertSame('250.00', (string) $second->grand_total);
    }

    public function test_series_are_per_property_and_financial_year(): void
    {
        $this->assertSame('2026-27', BillingNumbers::financialYear(CarbonImmutable::parse('2026-04-01')));
        $this->assertSame('2025-26', BillingNumbers::financialYear(CarbonImmutable::parse('2026-03-31')));
        $numbers = app(BillingNumbers::class);
        $a = $this->property->code;
        $b = $this->other->code;
        $this->assertSame($a.'/2627/00001', $numbers->invoiceNo($this->property, '2026-27'));
        $this->assertSame($a.'/2627/00002', $numbers->invoiceNo($this->property, '2026-27'));
        $this->assertSame($a.'/2728/00001', $numbers->invoiceNo($this->property, '2027-28'));
        $this->assertSame($a.'/C2627/0001', $numbers->invoiceNo($this->property, '2026-27', true));
        $this->assertSame($b.'/2627/00001', $numbers->invoiceNo($this->other, '2026-27'));
    }

    public function test_rolled_back_invoice_does_not_burn_a_number(): void
    {
        $r = $this->posted();
        try {
            DB::transaction(function () use ($r) {
                $this->within(fn () => $this->invoices()->issue($this->folios()->open($r), [], $this->owner));
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }
        $inv = $this->within(fn () => $this->invoices()->issue($this->folios()->open($r), [], $this->owner));
        $this->assertStringEndsWith('/00001', $inv->invoice_no);
    }

    public function test_void_after_invoicing_issues_a_credit_note(): void
    {
        $r = $this->posted(0, 1);
        $line = $this->within(fn () => $this->folios()->postCharge($r, ['type' => 'adjustment', 'description' => 'Laundry', 'unit_price' => '500', 'idempotency_key' => 'l'], $this->owner));
        $inv = $this->within(fn () => $this->invoices()->issue($this->folios()->open($r), [], $this->owner));
        $this->assertSame('4700.00', (string) $inv->grand_total);

        $this->within(fn () => $this->folios()->voidLine($line->fresh(), 'Not used', $this->owner));
        $note = Invoice::acrossProperties()->where('invoice_type', 'credit_note')->firstOrFail();
        $this->assertSame($inv->id, $note->original_invoice_id);
        $this->assertMatchesRegularExpression('#/C\d{4}/0001$#', $note->invoice_no);
        $this->assertSame('500.00', (string) $note->grand_total);
        $this->assertSame($inv->invoice_no, $note->snapshot['original']['number']);
        $this->assertSame('Not used', $note->snapshot['reason']);
        $this->assertSame($note->id, FolioLine::acrossProperties()->whereNotNull('void_of_line_id')->value('invoice_id'));
        $this->assertNull($inv->fresh()->cancelled_at, 'partial credit keeps the invoice valid');
    }

    public function test_cancellation_after_invoicing_credits_room_nights(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 4)]);
        $this->within(fn () => $this->folios()->postRoomNights($r, null, $this->owner));
        $inv = $this->within(fn () => $this->invoices()->issue($this->folios()->open($r), [], $this->owner));
        $this->within(fn () => $this->service()->cancel($r->fresh(), 'Guest request', $this->owner));
        $note = Invoice::acrossProperties()->where('invoice_type', 'credit_note')->firstOrFail();
        $this->assertSame((string) $inv->grand_total, (string) $note->grand_total);
        $this->assertSame('200.00', (string) $note->cgst_total);
        $this->assertSame('0.00', $this->within(fn () => $this->folios()->summary($r))['total']);
    }

    public function test_cancel_invoice_and_reissue_with_company_details(): void
    {
        $r = $this->posted();
        $inv = $this->within(fn () => $this->invoices()->issue($this->folios()->open($r), [], $this->owner));
        $note = $this->within(fn () => $this->invoices()->cancel($inv, 'Company GSTIN missing', $this->owner));
        $this->assertTrue($note->isCreditNote());
        $this->assertSame('8400.00', (string) $note->grand_total);
        $this->assertNotNull($inv->fresh()->cancelled_at);
        $this->assertSame(0, FolioLine::acrossProperties()->where('invoice_id', $inv->id)->count());

        $again = $this->within(fn () => $this->invoices()->issue($this->folios()->open($r), ['name' => 'Acme', 'tax_no' => '27AAACA1234A1Z5'], $this->owner));
        $this->assertStringEndsWith('/00002', $again->invoice_no);
        $this->assertSame('8400.00', (string) $again->grand_total);

        $this->expectException(ValidationException::class);
        $this->within(fn () => $this->invoices()->cancel($inv->fresh(), 'twice', $this->owner));
    }

    public function test_invoices_have_no_update_path(): void
    {
        $this->assertFalse(method_exists(\App\Domain\Billing\InvoiceService::class, 'update'));
        $this->assertFalse(method_exists(\App\Domain\Billing\InvoiceService::class, 'delete'));
    }
}
