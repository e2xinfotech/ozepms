<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Billing\FolioService;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Queries\BillingPresenter;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsBilling;
use App\Http\Requests\Property\Billing\CancelInvoiceRequest;
use App\Http\Requests\Property\Billing\IssueInvoiceRequest;
use App\Domain\Mail\ReservationMailer;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    public function index(mixed $property, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);

        return response()->json($this->presenter->invoices($r, $this->allowed('invoices.manage')));
    }

    public function store(IssueInvoiceRequest $request, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $v = $request->validated();
        $invoice = $this->invoices->issue($this->folios->open($r), [
            'name' => $v['bill_to_name'] ?? null, 'tax_no' => $v['bill_to_tax_no'] ?? null,
            'address' => $v['bill_to_address'] ?? null, 'state_code' => $v['bill_to_state'] ?? null,
        ], $request->user());
        try {
            app(ReservationMailer::class)->invoice($invoice, null, false, $request->user()->id);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['invoice' => $this->presenter->invoiceRow($invoice), 'message' => __('billing.messages.invoice_issued')]
            + $this->presenter->invoices($r, true), 201);
    }

    public function cancel(CancelInvoiceRequest $request, mixed $property, string $invoice): JsonResponse
    {
        $model = $this->invoiceOr404($invoice);
        $note = $this->invoices->cancel($model, (string) $request->validated('reason'), $request->user());
        $reservation = Reservation::query()->whereKey($model->folio()->value('reservation_id'))->firstOrFail();

        return response()->json(['credit_note' => $note->isCreditNote() ? $this->presenter->invoiceRow($note) : null, 'message' => __('billing.messages.invoice_cancelled')]
            + $this->presenter->invoices($reservation, true));
    }

    /** Sends an invoice or credit note by e-mail again (to the guest, or to another address). */
    public function email(Request $request, ReservationMailer $mailer, mixed $property, string $invoice): JsonResponse
    {
        $model = $this->invoiceOr404($invoice);
        $data = $request->validate(['to' => ['nullable', 'email:rfc', 'max:190']]);
        $log = $mailer->invoice($model, $data['to'] ?? null, true, $request->user()->id);
        if ($log === null) {
            throw ValidationException::withMessages(['to' => __('mailsettings.errors.no_address')]);
        }

        return response()->json(['message' => $log->status === 'failed' ? __('mailsettings.failed') : __('mailsettings.invoice_sent'), 'status' => $log->status]);
    }
}
