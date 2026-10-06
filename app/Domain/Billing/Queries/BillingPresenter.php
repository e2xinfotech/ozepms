<?php

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\FolioService;
use App\Domain\Billing\PaymentService;
use App\Domain\Billing\ServiceCatalogService;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TaxCategory;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** JSON shapes of the billing components (folio, payments, invoices, options). Ids are public ids. */
class BillingPresenter
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function folio(Reservation $reservation, array $can): array
    {
        $folio = $this->folios->folioOf($reservation);
        $summary = $this->folios->summary($reservation);
        $lines = $folio ? FolioLine::query()->where('folio_id', $folio->id)
            ->with(['taxes:id,folio_line_id,tax_rule_id,component,rate,tax_amount', 'poster:id,name'])
            ->orderBy('business_date')->orderBy('id')->get() : collect();
        $invoiceNos = $lines->pluck('invoice_id')->filter()->unique()->isEmpty() ? collect()
            : Invoice::query()->whereIn('id', $lines->pluck('invoice_id')->filter()->unique())->pluck('invoice_no', 'id');
        $places = Money::minorUnits((string) $reservation->currency_code);

        // Fixed fees (per night / per stay) are shown without a percentage.
        $ruleIds = $lines->flatMap(fn (FolioLine $l) => $l->taxes->pluck('tax_rule_id'))->filter()->unique()->values();
        $fixed = $ruleIds->isEmpty() ? [] : array_flip(DB::table('tax_rules')->whereIn('id', $ruleIds)->where('calc_type', '<>', 'percent')->pluck('id')->all());
        $label = fn ($t) => isset($fixed[$t->tax_rule_id]) ? (string) $t->component : $t->component.' '.rtrim(rtrim((string) $t->rate, '0'), '.').'%';

        $byComponent = [];
        $rows = $lines->map(function (FolioLine $l) use ($invoiceNos, $can, &$byComponent, $places, $label) {
            foreach ($l->taxes as $t) {
                $k = $label($t);
                $byComponent[$k] = Money::add($byComponent[$k] ?? '0', (string) $t->tax_amount);
            }

            return [
                'id' => $l->public_id,
                'date' => $l->business_date->toDateString(),
                'type' => $l->line_type,
                'description' => $l->description,
                'sac' => $l->sac_hsn_code,
                'quantity' => (string) $l->quantity,
                'unit_price' => Money::round((string) $l->unit_price, $places),
                'amount' => (string) $l->amount,
                'tax' => (string) $l->tax_amount,
                'total' => Money::round(Money::add((string) $l->amount, (string) $l->tax_amount), $places),
                'taxes' => $l->taxes->map(fn ($t) => ['component' => $t->component, 'label' => $label($t), 'rate' => (string) $t->rate, 'amount' => (string) $t->tax_amount])->all(),
                'void' => $l->is_void,
                'reversal' => $l->void_of_line_id !== null,
                'void_reason' => $l->void_reason,
                'invoice' => $l->invoice_id ? ($invoiceNos[$l->invoice_id] ?? null) : null,
                'posted_by' => $l->poster?->name,
                'posted_at' => $l->created_at?->toIso8601String(),
                'can_void' => ! $l->is_void && $l->void_of_line_id === null && $l->line_type !== 'room'
                    && (($l->line_type === 'cancellation_fee' && ($can['override'] ?? false)) || ($l->line_type !== 'cancellation_fee' && ($can['post'] ?? false))),
            ];
        })->all();

        $pending = DB::table('reservation_room_nights as n')
            ->join('reservation_rooms as rr', 'rr.id', '=', 'n.reservation_room_id')
            ->leftJoin('room_types as rt', 'rt.id', '=', 'rr.room_type_id')
            ->where('rr.property_id', $reservation->property_id)->where('rr.reservation_id', $reservation->id)->where('n.is_active', 1)
            ->whereNotExists(fn ($q) => $q->from('folio_lines as fl')->where('fl.property_id', $reservation->property_id)
                ->whereRaw("fl.live_key = CONCAT('room:', n.reservation_room_id, ':', n.stay_date)"))
            ->orderBy('n.stay_date')->orderBy('rr.sort_order')
            ->get(['n.stay_date', 'rt.name', 'n.net_price', 'n.tax_amount'])
            ->map(fn ($n) => [
                'date' => substr((string) $n->stay_date, 0, 10), 'description' => (string) $n->name,
                'amount' => (string) $n->net_price, 'tax' => (string) $n->tax_amount,
                'total' => Money::round(Money::add((string) $n->net_price, (string) $n->tax_amount), $places),
            ])->all();

        return [
            'folio' => $folio ? ['no' => $folio->folio_no, 'status' => $folio->status, 'currency' => $folio->currency_code] : null,
            'summary' => $summary,
            'lines' => $rows,
            'pending' => $pending,
            'taxes' => collect($byComponent)->map(fn ($v, $k) => ['label' => $k, 'amount' => Money::round($v, $places)])->values()->all(),
            'invoiceable' => $folio !== null && $lines->contains(fn (FolioLine $l) => $l->invoice_id === null && ! $l->is_void && $l->void_of_line_id === null),
            'can' => $can,
        ];
    }

    /** @return array<string, mixed> */
    public function payments(Reservation $reservation, bool $canManage): array
    {
        $rows = Payment::query()->where('reservation_id', $reservation->id)->with(['receiver:id,name', 'parent:id,public_id'])
            ->orderByRaw('COALESCE(received_at, created_at)')->orderBy('id')->get();
        $places = Money::minorUnits((string) $reservation->currency_code);

        return [
            'rows' => $rows->map(fn (Payment $p) => [
                'id' => $p->public_id,
                'kind' => $p->kind,
                'method' => $p->method,
                'gateway' => $p->gateway,
                'amount' => (string) $p->amount,
                'refunded' => (string) $p->refunded_amount,
                'refundable' => $p->kind === 'payment' && in_array($p->status, ['captured', 'partially_refunded'], true)
                    ? Money::round(Money::sub((string) $p->amount, (string) $p->refunded_amount), $places) : '0.00',
                'status' => $p->status,
                'deposit' => $p->is_deposit,
                'reference' => $p->reference,
                'notes' => $p->notes,
                'failure' => $p->failure_reason,
                'parent' => $p->parent?->public_id,
                'date' => ($p->received_at ?? $p->created_at)?->toIso8601String(),
                'received_by' => $p->receiver?->name,
            ])->all(),
            'summary' => $this->folios->summary($reservation),
            'can' => ['manage' => $canManage, 'online' => $canManage && $this->payments->onlineEnabled()],
        ];
    }

    /** @return array<string, mixed> */
    public function invoices(Reservation $reservation, bool $canManage): array
    {
        $folio = $this->folios->folioOf($reservation);
        $rows = $folio ? Invoice::query()->where('folio_id', $folio->id)->with('original:id,invoice_no')->orderBy('id')->get() : collect();
        $billable = $folio ? FolioLine::query()->where('folio_id', $folio->id)->whereNull('invoice_id')->where('is_void', false)->whereNull('void_of_line_id')->exists() : false;

        return [
            'rows' => $rows->map(fn (Invoice $i) => $this->invoiceRow($i))->all(),
            'billable' => $billable,
            'pending_room_charges' => $this->folios->summary($reservation)['pending_room_charges'],
            'can' => ['manage' => $canManage],
        ];
    }

    /** @return array<string, mixed> */
    public function invoiceRow(Invoice $i): array
    {
        return [
            'id' => $i->public_id,
            'number' => $i->invoice_no,
            'type' => $i->invoice_type,
            'date' => $i->invoice_date->toDateString(),
            'bill_to' => $i->bill_to_name,
            'tax_no' => $i->bill_to_tax_no,
            'taxable' => (string) $i->taxable_total,
            'tax' => Money::round(Money::add((string) $i->cgst_total, (string) $i->sgst_total, (string) $i->igst_total, (string) $i->other_tax_total), 2),
            'total' => (string) $i->grand_total,
            'currency' => $i->currency_code,
            'cancelled' => $i->cancelled_at !== null,
            'original' => $i->original?->invoice_no,
            'url' => route('property.invoices.show', ['property' => app(\App\Support\PropertyContext::class)->property()->code, 'invoice' => $i->public_id]),
        ];
    }

    /** Lists for the charge / payment dialogs. @return array<string, mixed> */
    public function options(?Reservation $reservation): array
    {
        $nights = $reservation ? (int) $reservation->nights : 1;
        $persons = $reservation ? (int) $reservation->adults + (int) $reservation->children : 1;
        $categories = TaxCategory::query()->orderBy('id')->get(['id', 'code', 'name', 'default_sac_hsn']);

        return [
            'services' => Service::query()->where('is_active', true)->with('taxCategory:id,code')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Service $s) => [
                    'id' => $s->public_id, 'code' => $s->code, 'name' => $s->name, 'price' => (string) $s->price,
                    'posting_rule' => $s->posting_rule, 'tax_category' => $s->taxCategory?->code,
                    'quantity' => ServiceCatalogService::quantityFor($s, $nights, $persons),
                ])->all(),
            'tax_categories' => $categories->map(fn ($c) => ['value' => $c->code, 'label' => __('billing.tax_categories.'.$c->code) !== 'billing.tax_categories.'.$c->code ? __('billing.tax_categories.'.$c->code) : $c->name, 'sac' => $c->default_sac_hsn])->all(),
            'methods' => Payment::MANUAL_METHODS,
            'online' => $this->payments->onlineEnabled(),
            'stay' => ['nights' => $nights, 'persons' => $persons],
        ];
    }

    /** Folio print view (all lines, payments, summary). @return array<string, mixed> */
    public function printable(Reservation $reservation, Folio $folio): array
    {
        return [
            'folio' => $this->folio($reservation, []),
            'payments' => $this->payments($reservation, false)['rows'],
        ];
    }
}
