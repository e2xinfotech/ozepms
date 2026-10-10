<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Billing\FolioService;
use App\Domain\Billing\Queries\BillingPresenter;
use App\Domain\Billing\Queries\ServiceQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Property\Concerns\ChecksPermissions;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TaxCategory;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Billing pages: services setup, printable invoice / credit note, printable folio. */
class BillingController extends Controller
{
    use ChecksPermissions;

    public function services(Request $request, ServiceQuery $query, PropertyContext $context): View
    {
        return Page::render('property/services/index', [
            'list' => $query->page($request),
            'filters' => $request->only(['q', 'status', 'posting_rule', 'sort', 'dir', 'selected']),
            'options' => [
                'tax_categories' => TaxCategory::query()->orderBy('id')->get(['code', 'name', 'default_sac_hsn'])
                    ->map(fn ($c) => ['value' => $c->code, 'label' => __('billing.tax_categories.'.$c->code) !== 'billing.tax_categories.'.$c->code ? __('billing.tax_categories.'.$c->code) : $c->name, 'sac' => $c->default_sac_hsn])->all(),
                'posting_rules' => Service::POSTING_RULES,
                'departments' => Service::DEPARTMENTS,
                // SAC/HSN codes are part of Indian GST only.
                'show_sac' => $context->property()->country_iso2 === 'IN',
            ],
        ], __('billing.services.title'));
    }

    /** The invoice or credit note as a PDF download. */
    public function invoicePdf(mixed $property, string $invoice, \App\Domain\Billing\InvoicePdf $pdf): \Symfony\Component\HttpFoundation\Response
    {
        $model = Invoice::query()->where('public_id', $invoice)->firstOrFail();

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$pdf->fileName($model).'"', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function invoice(mixed $property, string $invoice, PropertyContext $context): View
    {
        $model = Invoice::query()->where('public_id', $invoice)->with(['original:id,public_id,invoice_no', 'creditNotes:id,public_id,original_invoice_id,invoice_no,grand_total,invoice_date'])->firstOrFail();
        $reservation = Reservation::query()->whereKey($model->folio()->value('reservation_id'))->first(['id', 'public_id', 'booking_ref']);

        return Page::render('property/billing/invoice', [
            'invoice' => [
                'id' => $model->public_id,
                'type' => $model->invoice_type,
                'number' => $model->invoice_no,
                'cancelled_at' => $model->cancelled_at?->toIso8601String(),
                'original' => $model->original ? ['id' => $model->original->public_id, 'number' => $model->original->invoice_no] : null,
                'credit_notes' => $model->creditNotes->map(fn (Invoice $n) => ['id' => $n->public_id, 'number' => $n->invoice_no, 'total' => (string) $n->grand_total, 'date' => $n->invoice_date->toDateString()])->all(),
                'snapshot' => $model->snapshot,
                'logo' => $context->property()->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($context->property()->logo_path) : null,
            ],
            'reservation' => $reservation ? ['id' => $reservation->public_id, 'ref' => $reservation->booking_ref] : null,
            'can' => $this->can(['cancel' => 'invoices.manage']),
        ], $model->invoice_no);
    }

    public function folio(mixed $property, string $reservation, BillingPresenter $presenter, FolioService $folios, PropertyContext $context): View
    {
        $r = Reservation::query()->where('public_id', $reservation)->firstOrFail();
        $property = $context->property();

        return Page::render('property/billing/folio', [
            'reservation' => [
                'id' => $r->public_id, 'ref' => $r->booking_ref, 'guest_name' => $r->guest_name, 'status' => $r->status,
                'check_in' => $r->check_in->toDateString(), 'check_out' => $r->check_out->toDateString(), 'nights' => (int) $r->nights,
                'currency' => $r->currency_code,
            ],
            'property' => [
                'name' => $property->name, 'legal_name' => $property->legal_name, 'tax_no' => $property->tax_registration_no,
                'address' => array_values(array_filter([$property->address_line1, $property->address_line2, trim($property->city.' '.$property->postcode)])),
                'phone' => $property->phone, 'email' => $property->email,
            ],
            'data' => $presenter->printable($r, $folios->open($r)),
        ], $r->booking_ref);
    }
}
