<?php

namespace App\Http\Controllers\Booking;

use App\Domain\BookingEngine\BookingEngineService;
use App\Domain\BookingEngine\BookingPresenter;
use App\Domain\Billing\PaymentService;
use App\Http\Controllers\Booking\Concerns\ResolvesBookingProperty;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\BookRequest;
use App\Http\Requests\Booking\SearchRequest;
use App\Domain\Mail\ReservationMailer;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Public booking engine JSON endpoints (no login, rate limited, CSRF-protected). */
class BookingApiController extends Controller
{
    use ResolvesBookingProperty;

    public function __construct(
        private readonly BookingEngineService $engine,
        private readonly BookingPresenter $presenter,
    ) {}

    public function search(SearchRequest $request, string $code): JsonResponse
    {
        $property = $this->bookingProperty($code);
        $this->bookingLocale($request, $property);

        return response()->json($this->engine->search($property, $request->validated() + ['children' => 0, 'infants' => 0]));
    }

    public function book(BookRequest $request, string $code): JsonResponse
    {
        $property = $this->bookingProperty($code);
        $this->bookingLocale($request, $property);
        $result = $this->engine->book($property, $request->validated());
        $reservation = $result['reservation'];
        if (! $result['payment']) {
            $this->notify($reservation);
        }

        return response()->json([
            'booking' => ['ref' => $reservation->booking_ref, 'status' => $reservation->status, 'due' => $result['due']],
            'confirmation_url' => $this->presenter->confirmationUrl($reservation),
            'payment' => $result['payment'],
        ], 201);
    }

    /** Reopens the payment window of a booking still holding its rooms. */
    public function checkout(Request $request, PaymentService $payments, string $code, string $payment): JsonResponse
    {
        $property = $this->bookingProperty($code);
        $model = Payment::acrossProperties()->where('property_id', $property->id)->where('public_id', $payment)
            ->where('method', 'gateway')->where('status', 'pending')->whereNotNull('gateway_order_id')->firstOrFail();
        $reservation = Reservation::acrossProperties()->where('property_id', $property->id)->where('status', 'pending')
            ->where('hold_expires_at', '>', now())->findOrFail($model->reservation_id);

        return response()->json(['payment' => ['id' => $model->public_id, 'checkout' => $payments->checkoutData($model, $reservation)]]);
    }

    public function verify(Request $request, PaymentService $payments, string $code, string $payment): JsonResponse
    {
        $property = $this->bookingProperty($code);
        $this->bookingLocale($request, $property);
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:64'], 'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:256'],
        ]);
        $model = Payment::acrossProperties()->where('property_id', $property->id)->where('public_id', $payment)->firstOrFail();
        $reservation = Reservation::acrossProperties()->where('property_id', $property->id)->findOrFail($model->reservation_id);
        $payments->verifyOnline($model, $data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature']);
        $reservation = $this->engine->confirmIfPaid($reservation->fresh());
        $this->notify($reservation);

        return response()->json(['booking' => ['ref' => $reservation->booking_ref, 'status' => $reservation->status], 'confirmation_url' => $this->presenter->confirmationUrl($reservation)]);
    }

    private function notify(Reservation $reservation): void
    {
        try {
            app(ReservationMailer::class)->notify($reservation->fresh(), 'booking_confirmation');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
