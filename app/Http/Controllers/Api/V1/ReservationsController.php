<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\BookingEngine\BookingEngineService;
use App\Domain\BookingEngine\BookingPresenter;
use App\Domain\Mail\ReservationMailer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\ApiBookRequest;
use App\Models\Reservation;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** /api/v1/reservations — create (same booking path as the booking page) and read by booking reference. */
class ReservationsController extends Controller
{
    public function __construct(
        private readonly BookingEngineService $engine,
        private readonly BookingPresenter $presenter,
    ) {}

    public function store(ApiBookRequest $request, PropertyContext $context): JsonResponse
    {
        $data = $request->validated();
        $data['idempotency_key'] = $data['idempotency_key'] ?? $request->header('Idempotency-Key');
        $result = $this->engine->book($context->property(), $data);
        $r = $result['reservation'];
        if (! $result['payment']) {
            try {
                app(ReservationMailer::class)->notify($r->fresh(), 'booking_confirmation');
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['data' => $this->presenter->booking($r->fresh()) + [
            'confirmation_url' => $this->presenter->confirmationUrl($r),
            'payment' => ['due_now' => $result['due'], 'checkout' => $result['payment']['checkout'] ?? null, 'payment_id' => $result['payment']['id'] ?? null],
        ]], 201);
    }

    public function show(Request $request, PropertyContext $context, string $ref): JsonResponse
    {
        $r = Reservation::query()->where('booking_ref', $ref)->firstOrFail();

        return response()->json(['data' => $this->presenter->booking($r)]);
    }
}
