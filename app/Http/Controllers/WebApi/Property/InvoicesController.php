<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Billing\FolioService;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Queries\BillingPresenter;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsBilling;
use App\Http\Requests\Property\Billing\CancelInvoiceRequest;
use App\Http\Requests\Property\Billing\IssueInvoiceRequest;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;

/** Tax invoices and credit notes of a reservation's folio. */
class InvoicesController extends Controller
{
    use FindsBilling;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly FolioService $folios,
        private readonly BillingPresenter $presenter,
    ) {}

    public function index(string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);

        return response()->json($this->presenter->invoices($r, $this->allowed('invoices.manage')));
    }

    public function store(IssueInvoiceRequest $request, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $v = $request->validated();
        $invoice = $this->invoices->issue($this->folios->open($r), [
            'name' => $v['bill_to_name'] ?? null, 'tax_no' => $v['bill_to_tax_no'] ?? null,
            'address' => $v['bill_to_address'] ?? null, 'state_code' => $v['bill_to_state'] ?? null,
        ], $request->user());

        return response()->json(['invoice' => $this->presenter->invoiceRow($invoice), 'message' => __('billing.messages.invoice_issued')]
            + $this->presenter->invoices($r, true), 201);
    }

    public function cancel(CancelInvoiceRequest $request, string $invoice): JsonResponse
    {
        $model = $this->invoiceOr404($invoice);
        $note = $this->invoices->cancel($model, (string) $request->validated('reason'), $request->user());
        $reservation = Reservation::query()->whereKey($model->folio()->value('reservation_id'))->firstOrFail();

        return response()->json(['credit_note' => $note->isCreditNote() ? $this->presenter->invoiceRow($note) : null, 'message' => __('billing.messages.invoice_cancelled')]
            + $this->presenter->invoices($reservation, true));
    }
}
