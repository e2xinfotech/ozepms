<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\State;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GST tax invoices and credit notes.
 *
 * * A tax invoice bills every folio line not billed yet (voided lines and their reversals cancel
 *   out and are left off). Numbers come from a per-property, per-financial-year series that is
 *   gap-free (locked counter row in the same transaction): P1001/2627/00123 (max 16 characters).
 * * Invoices are immutable: what is printed is frozen in `snapshot`. Corrections are credit notes
 *   (own series P1001/C2627/0012): automatically when an invoiced line is voided (cancellation,
 *   stay shortened, waived extra), or for the whole invoice when it is cancelled (its open lines
 *   can then be invoiced again, e.g. with the company's GSTIN).
 * * Accommodation is supplied where the property is: place of supply = property state, CGST + SGST;
 *   IGST appears only when TaxService produced it for a line.
 */
class InvoiceService
{
    public function __construct(
        private readonly BillingNumbers $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Issues a tax invoice for the folio's lines not billed yet.
     *
     * @param  array{name?: ?string, tax_no?: ?string, address?: ?string, state_code?: ?string}  $billTo  overrides of the guest's details
     */
    public function issue(Folio $folio, array $billTo = [], ?User $by = null): Invoice
    {
        $property = Property::query()->findOrFail($folio->property_id);
        $reservation = Reservation::query()->where('property_id', $folio->property_id)->findOrFail($folio->reservation_id);

        return Tx::run(function () use ($folio, $property, $reservation, $billTo, $by) {
            $folio = Folio::query()->where('property_id', $folio->property_id)->whereKey($folio->id)->lockForUpdate()->firstOrFail();
            $lines = $this->billableLines($folio);
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['invoice' => __('billing.errors.nothing_to_invoice')]);
            }
            $date = BusinessDate::localToday($property);
            $fy = BillingNumbers::financialYear($date);
            $parties = $this->parties($property, $reservation, $folio, $billTo);
            $body = $this->body($lines, (string) $folio->currency_code, false);
            if (Money::isNegative($body['totals']['grand'])) {
                throw ValidationException::withMessages(['invoice' => __('billing.errors.negative_invoice')]);
            }

            // Written once, complete (invoices are never updated afterwards except cancelled_at).
            $invoice = new Invoice([
                'property_id' => $folio->property_id,
                'folio_id' => $folio->id,
                'invoice_type' => 'tax_invoice',
                'invoice_no' => $this->numbers->invoiceNo($property, $fy),
                'financial_year' => $fy,
                'invoice_date' => $date->toDateString(),
                'issued_by' => $by?->id,
            ] + $this->columns($parties, $body, (string) $folio->currency_code));
            $invoice->snapshot = $this->snapshot($invoice, $property, $reservation, $folio, $parties, $body);
            $invoice->save();

            FolioLine::query()->where('folio_id', $folio->id)->whereIn('id', $lines->pluck('id'))->update(['invoice_id' => $invoice->id]);
            $this->audit->log('invoice.issued', $invoice, ['after' => ['invoice_no' => $invoice->invoice_no, 'grand_total' => (string) $invoice->grand_total]], $folio->property_id, $by?->id);

            return $invoice;
        });
    }

    /** True when the folio has lines that are not on an invoice yet. */
    public function hasBillableLines(Folio $folio): bool
    {
        return $this->billableLines($folio)->isNotEmpty();
    }

    /**
     * Credit note for reversal lines of an invoiced line (called by FolioService inside its
     * transaction, with the folio locked).
     *
     * @param  list<FolioLine>  $reversals
     */
    public function creditLines(Folio $folio, int $invoiceId, array $reversals, string $reason, ?User $by = null): Invoice
    {
        $original = Invoice::query()->where('property_id', $folio->property_id)->findOrFail($invoiceId);
        $lines = FolioLine::query()->whereIn('id', array_map(fn (FolioLine $l) => $l->id, $reversals))->with('taxes')->get();
        $note = $this->creditNote($original, $folio, $this->body($lines, (string) $folio->currency_code, true), $reason, $by);
        FolioLine::query()->whereIn('id', $lines->pluck('id'))->update(['invoice_id' => $note->id]);

        return $note;
    }

    /**
     * Cancels a tax invoice with a credit note for everything on it not credited yet; those
     * lines become billable again.
     */
    public function cancel(Invoice $invoice, string $reason, ?User $by = null): Invoice
    {
        if ($invoice->isCreditNote()) {
            throw ValidationException::withMessages(['invoice' => __('billing.errors.cannot_cancel_credit_note')]);
        }

        return Tx::run(function () use ($invoice, $reason, $by) {
            $folio = Folio::query()->where('property_id', $invoice->property_id)->whereKey($invoice->folio_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->where('property_id', $invoice->property_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->cancelled_at !== null) {
                throw ValidationException::withMessages(['invoice' => __('billing.errors.already_cancelled')]);
            }
            $open = FolioLine::query()->where('folio_id', $folio->id)->where('invoice_id', $invoice->id)->where('is_void', false)
                ->with('taxes')->orderBy('business_date')->orderBy('id')->get();
            $note = null;
            if ($open->isNotEmpty()) {
                $note = $this->creditNote($invoice, $folio, $this->body($open, (string) $folio->currency_code, true, true), $reason, $by);
                FolioLine::query()->whereIn('id', $open->pluck('id'))->update(['invoice_id' => null]);
            }
            $invoice->cancelled_at = now();
            $invoice->save();
            $this->audit->log('invoice.cancelled', $invoice, ['after' => ['reason' => $reason, 'credit_note' => $note?->invoice_no]], $invoice->property_id, $by?->id);

            return $note ?? $invoice;
        });
    }

    // ------------------------------------------------------------------ internals

    /** @param  array<string, mixed>  $body */
    private function creditNote(Invoice $original, Folio $folio, array $body, string $reason, ?User $by): Invoice
    {
        $property = Property::query()->findOrFail($folio->property_id);
        $reservation = Reservation::query()->where('property_id', $folio->property_id)->findOrFail($folio->reservation_id);
        $date = BusinessDate::localToday($property);
        $fy = BillingNumbers::financialYear($date);
        $parties = $original->snapshot['parties'] ?? $this->parties($property, $reservation, $folio, []);

        $note = new Invoice([
            'property_id' => $folio->property_id,
            'folio_id' => $folio->id,
            'invoice_type' => 'credit_note',
            'original_invoice_id' => $original->id,
            'invoice_no' => $this->numbers->invoiceNo($property, $fy, true),
            'financial_year' => $fy,
            'invoice_date' => $date->toDateString(),
            'issued_by' => $by?->id,
        ] + $this->columns($parties, $body, (string) $folio->currency_code));
        $snapshot = $this->snapshot($note, $property, $reservation, $folio, $parties, $body);
        $snapshot['original'] = ['number' => $original->invoice_no, 'date' => $original->invoice_date->toDateString(), 'id' => $original->public_id];
        $snapshot['reason'] = mb_substr($reason, 0, 255);
        $note->snapshot = $snapshot;
        $note->save();
        $this->audit->log('invoice.credit_note_issued', $note, ['after' => [
            'invoice_no' => $note->invoice_no, 'original' => $original->invoice_no, 'grand_total' => (string) $note->grand_total, 'reason' => $reason,
        ]], $folio->property_id, $by?->id);

        return $note;
    }

    /** @return Collection<int, FolioLine> lines not invoiced yet, without void/reversal pairs */
    private function billableLines(Folio $folio): Collection
    {
        $lines = FolioLine::query()->where('folio_id', $folio->id)->whereNull('invoice_id')
            ->with('taxes')->orderBy('business_date')->orderBy('id')->get();
        $reversed = $lines->whereNotNull('void_of_line_id')->pluck('void_of_line_id')->flip();
        $ids = $lines->pluck('id')->flip();

        return $lines->reject(fn (FolioLine $l) => ($l->is_void && isset($reversed[$l->id]))
            || ($l->void_of_line_id !== null && isset($ids[$l->void_of_line_id])))->values();
    }

    /**
     * Lines, tax summary and totals. Credit notes show the reductions as positive amounts
     * ($negate for reversal lines; $asIs for original lines credited in full).
     *
     * @param  Collection<int, FolioLine>  $lines
     * @return array<string, mixed>
     */
    private function body(Collection $lines, string $currency, bool $credit, bool $asIs = false): array
    {
        $places = Money::minorUnits($currency);
        $sign = fn (string $v) => $credit && ! $asIs ? Money::negate($v) : Money::normalize($v);
        $out = [];
        $summary = [];
        $totals = ['taxable' => '0', 'cgst' => '0', 'sgst' => '0', 'igst' => '0', 'other' => '0'];
        // Fixed fees (per night / per stay) carry an amount, not a percentage.
        $ruleIds = $lines->flatMap(fn ($l) => $l->taxes->pluck('tax_rule_id'))->filter()->unique()->values();
        $fixed = $ruleIds->isEmpty() ? [] : array_flip(DB::table('tax_rules')->whereIn('id', $ruleIds)->where('calc_type', '<>', 'percent')->pluck('id')->all());
        foreach ($lines as $line) {
            $taxes = [];
            foreach ($line->taxes as $t) {
                $amount = $sign((string) $t->tax_amount);
                $isFixed = isset($fixed[$t->tax_rule_id]);
                $taxes[] = ['component' => $t->component, 'name' => $t->tax_name, 'rate' => Money::round((string) $t->rate, 2), 'fixed' => $isFixed, 'amount' => Money::round($amount, $places)];
                $bucket = in_array($t->component, ['CGST', 'SGST', 'IGST'], true) ? strtolower($t->component) : 'other';
                $totals[$bucket] = Money::add($totals[$bucket], $amount);
                $k = $t->component.'|'.Money::round((string) $t->rate, 2);
                $summary[$k] ??= ['component' => $t->component, 'name' => $t->tax_name, 'rate' => Money::round((string) $t->rate, 2), 'fixed' => $isFixed, 'taxable' => '0', 'amount' => '0'];
                $summary[$k]['taxable'] = Money::add($summary[$k]['taxable'], $sign((string) $t->taxable_amount));
                $summary[$k]['amount'] = Money::add($summary[$k]['amount'], $amount);
            }
            $taxable = $sign((string) $line->amount);
            $tax = $sign((string) $line->tax_amount);
            $totals['taxable'] = Money::add($totals['taxable'], $taxable);
            $out[] = [
                'date' => $line->business_date->toDateString(),
                'type' => $line->line_type,
                'description' => $line->description,
                'department' => $line->department,
                'reference' => $line->reference,
                'sac' => $line->sac_hsn_code,
                'quantity' => Money::round((string) $line->quantity, 2),
                'unit_price' => Money::round($sign((string) $line->unit_price), $places),
                'taxable' => Money::round($taxable, $places),
                'taxes' => $taxes,
                'tax_total' => Money::round($tax, $places),
                'total' => Money::round(Money::add($taxable, $tax), $places),
            ];
        }
        $taxTotal = Money::add($totals['cgst'], $totals['sgst'], $totals['igst'], $totals['other']);
        $exact = Money::add($totals['taxable'], $taxTotal);
        $grand = config('ozepms.billing.invoice_round_off') ? Money::round($exact, 0) : Money::round($exact, $places);

        return [
            'lines' => $out,
            'tax_summary' => array_values(array_map(fn ($s) => [...$s, 'taxable' => Money::round($s['taxable'], $places), 'amount' => Money::round($s['amount'], $places)], $summary)),
            'totals' => [
                'taxable' => Money::round($totals['taxable'], $places),
                'cgst' => Money::round($totals['cgst'], $places),
                'sgst' => Money::round($totals['sgst'], $places),
                'igst' => Money::round($totals['igst'], $places),
                'other' => Money::round($totals['other'], $places),
                'tax' => Money::round($taxTotal, $places),
                'round_off' => Money::round(Money::sub($grand, $exact), $places),
                'grand' => Money::round($grand, $places),
            ],
        ];
    }

    /** @return array<string, mixed> invoice columns from parties and body */
    private function columns(array $parties, array $body, string $currency): array
    {
        $t = $body['totals'];

        return [
            'supplier_tax_no' => $parties['supplier']['tax_no'],
            'supplier_state_code' => $parties['supplier']['state_code'],
            'bill_to_name' => mb_substr((string) $parties['bill_to']['name'], 0, 190),
            'bill_to_tax_no' => $parties['bill_to']['tax_no'],
            'bill_to_address' => $parties['bill_to']['address'] !== null ? mb_substr($parties['bill_to']['address'], 0, 500) : null,
            'place_of_supply' => $parties['place_of_supply']['code'],
            'currency_code' => $currency,
            'taxable_total' => $t['taxable'],
            'cgst_total' => $t['cgst'],
            'sgst_total' => $t['sgst'],
            'igst_total' => $t['igst'],
            'other_tax_total' => $t['other'],
            'round_off' => $t['round_off'],
            'grand_total' => $t['grand'],
        ];
    }

    /** Supplier (property), recipient (guest / company) and place of supply. */
    private function parties(Property $property, Reservation $reservation, Folio $folio, array $billTo): array
    {
        $state = $property->state_id ? State::query()->find($property->state_id) : null;
        $guestId = $folio->bill_to_guest_id ?: $reservation->primary_guest_id;
        $guest = $guestId ? Guest::query()->where('property_id', $property->id)->find($guestId) : null;
        $guestState = $guest?->state_id ? State::query()->find($guest->state_id) : null;

        $guestName = $guest ? trim($guest->first_name.' '.$guest->last_name) : (string) $reservation->guest_name;
        $company = $guest?->company_tax_no ? ($guest->company_name ?: $guestName) : null;
        $address = $guest ? implode(', ', array_filter([$guest->address_line1, $guest->address_line2, $guest->city, $guestState?->name, $guest->postcode])) : '';

        $recipientStateCode = $billTo['state_code'] ?? null;
        $recipientState = $recipientStateCode ? State::query()->where(fn ($q) => $q->where('code', $recipientStateCode)->orWhere('tax_region_code', $recipientStateCode))->first() : $guestState;

        return [
            'supplier' => [
                'name' => (string) $property->name,
                'legal_name' => (string) ($property->legal_name ?: $property->name),
                'address' => array_values(array_filter([$property->address_line1, $property->address_line2,
                    trim(implode(' ', array_filter([$property->city, $property->postcode]))), $state?->name])),
                'tax_no' => $property->tax_registration_no ?: null,
                'state' => $state?->name,
                'state_code' => $state ? ($state->tax_region_code ?: $state->code) : null,
                'phone' => $property->phone,
                'email' => $property->email,
                'country' => $property->country_iso2,
            ],
            'bill_to' => [
                'name' => trim((string) ($billTo['name'] ?? '')) ?: ($company ?: ($guestName ?: '-')),
                'tax_no' => array_key_exists('tax_no', $billTo) && $billTo['tax_no'] !== null && $billTo['tax_no'] !== '' ? strtoupper(trim($billTo['tax_no'])) : ($guest?->company_tax_no ?: null),
                'address' => trim((string) ($billTo['address'] ?? '')) ?: ($address ?: null),
                'state' => $recipientState?->name,
                'state_code' => $recipientState ? ($recipientState->tax_region_code ?: $recipientState->code) : null,
                'guest_name' => $guestName,
            ],
            // Accommodation: place of supply is where the property is.
            'place_of_supply' => ['code' => $state ? ($state->tax_region_code ?: $state->code) : null, 'name' => $state?->name],
        ];
    }

    /** @return array<string, mixed> the frozen printable document */
    private function snapshot(Invoice $invoice, Property $property, Reservation $reservation, Folio $folio, array $parties, array $body): array
    {
        $rooms = DB::table('reservation_rooms as rr')->leftJoin('room_types as rt', 'rt.id', '=', 'rr.room_type_id')
            ->where('rr.reservation_id', $reservation->id)->orderBy('rr.sort_order')->pluck('rt.name')->filter()->values()->all();
        $payments = DB::table('payments')->where('reservation_id', $reservation->id)->where('property_id', $reservation->property_id)
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('kind', 'payment')->whereIn('status', FolioService::RECEIVED))->orWhere(fn ($r) => $r->where('kind', 'refund')->where('status', 'captured')))
            ->orderBy('received_at')->get(['kind', 'method', 'amount', 'received_at', 'reference']);

        return [
            'type' => $invoice->invoice_type,
            'number' => $invoice->invoice_no,
            'date' => $invoice->invoice_date->toDateString(),
            'financial_year' => $invoice->financial_year,
            'currency' => (string) $folio->currency_code,
            'parties' => $parties,
            'reservation' => [
                'ref' => $reservation->booking_ref,
                'folio_no' => $folio->folio_no,
                'guest_name' => $reservation->guest_name,
                'check_in' => $reservation->check_in?->toDateString(),
                'check_out' => $reservation->check_out?->toDateString(),
                'nights' => (int) $reservation->nights,
                'rooms' => $rooms,
                'adults' => (int) $reservation->adults,
                'children' => (int) $reservation->children,
            ],
            'lines' => $body['lines'],
            'tax_summary' => $body['tax_summary'],
            'totals' => $body['totals'],
            'amount_in_words' => AmountInWords::format($body['totals']['grand'], (string) $folio->currency_code),
            'payments' => $payments->map(fn ($p) => [
                'kind' => $p->kind, 'method' => $p->method, 'amount' => (string) $p->amount,
                'date' => $p->received_at ? substr((string) $p->received_at, 0, 10) : null, 'reference' => $p->reference,
            ])->all(),
            'logo' => $property->logo_path,
        ];
    }
}
