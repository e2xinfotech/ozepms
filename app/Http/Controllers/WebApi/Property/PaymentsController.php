<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Billing\PaymentService;
use App\Domain\Billing\Queries\BillingPresenter;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsBilling;
use App\Http\Requests\Property\Billing\OnlinePaymentRequest;
use App\Http\Requests\Property\Billing\RecordPaymentRequest;
use App\Http\Requests\Property\Billing\RefundPaymentRequest;
use App\Http\Requests\Property\Billing\VerifyOnlinePaymentRequest;
use Illuminate\Http\JsonResponse;

/** Payments, refunds and online (Razorpay) payments of a reservation. */
class PaymentsController extends Controller
{
    use FindsBilling;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly BillingPresenter $presenter,
    ) {}

    public function index(string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);

        return response()->json($this->presenter->payments($r, $this->allowed('payments.manage')));
    }

    public function store(RecordPaymentRequest $request, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $payment = $this->payments->record($r, $request->validated(), $request->user());

        return $this->done($r, $payment->public_id, $this->payments->replayed ? 200 : 201, 'billing.messages.payment_recorded');
    }

    public function refund(RefundPaymentRequest $request, string $reservation, string $payment): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $refund = $this->payments->refund($this->paymentOr404($r, $payment), $request->validated(), $request->user());

        return $this->done($r, $refund->public_id, $this->payments->replayed ? 200 : 201,
            $refund->status === 'pending' ? 'billing.messages.refund_pending' : 'billing.messages.refund_recorded');
    }

    /** Creates the gateway order and returns what Razorpay Checkout needs. */
    public function online(OnlinePaymentRequest $request, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $payment = $this->payments->startOnline($r, $request->validated(), $request->user());
        $r->loadMissing(['primaryGuest', 'property']);

        return response()->json(['checkout' => $this->payments->checkoutData($payment, $r)], $this->payments->replayed ? 200 : 201);
    }

    public function verify(VerifyOnlinePaymentRequest $request, string $reservation, string $payment): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $v = $request->validated();
        $model = $this->payments->verifyOnline($this->paymentOr404($r, $payment), $v['razorpay_order_id'], $v['razorpay_payment_id'], $v['razorpay_signature'], $request->user());

        return $this->done($r, $model->public_id, 200, 'billing.messages.payment_recorded');
    }

    private function done($reservation, string $id, int $status, string $message): JsonResponse
    {
        return response()->json(['id' => $id, 'replayed' => $this->payments->replayed, 'message' => __($message)]
            + $this->presenter->payments($reservation->fresh(), $this->allowed('payments.manage')), $status);
    }
}
