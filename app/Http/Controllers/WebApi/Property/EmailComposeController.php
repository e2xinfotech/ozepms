<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Mail\ReservationMailer;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsReservations;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** "Send e-mail" from a reservation or a guest profile: the confirmation again, or a message written by the hotel. */
class EmailComposeController extends Controller
{
    use FindsReservations;

    public function reservation(Request $request, PropertyContext $context, ReservationMailer $mailer, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $data = $request->validate([
            'type' => ['required', 'in:confirmation,message'],
            'subject' => ['required_if:type,message', 'nullable', 'string', 'max:200'],
            'body' => ['required_if:type,message', 'nullable', 'string', 'max:5000'],
        ]);
        $guest = $r->primaryGuest;
        if (! filled($guest?->email)) {
            throw ValidationException::withMessages(['type' => __('mailsettings.errors.no_address')]);
        }
        $log = $data['type'] === 'confirmation'
            ? $mailer->notify($r, 'booking_confirmation', true, $request->user()->id)
            : $mailer->message($context->property(), (string) $guest->email, $r->guest_name, $data['subject'], $data['body'], $r->id, $request->user()->id);

        return response()->json(['message' => $log?->status === 'failed' ? __('mailsettings.failed') : __('mailsettings.sent_to', ['to' => $guest->email]), 'status' => $log?->status]);
    }

    public function guest(Request $request, PropertyContext $context, ReservationMailer $mailer, mixed $property, string $guest): JsonResponse
    {
        $g = $this->guestOr404($guest);
        $data = $request->validate(['subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:5000']]);
        if (! filled($g->email)) {
            throw ValidationException::withMessages(['subject' => __('mailsettings.errors.no_address')]);
        }
        $log = $mailer->message($context->property(), (string) $g->email, trim($g->first_name.' '.($g->last_name ?? '')), $data['subject'], $data['body'], null, $request->user()->id);

        return response()->json(['message' => $log->status === 'failed' ? __('mailsettings.failed') : __('mailsettings.sent_to', ['to' => $g->email]), 'status' => $log->status]);
    }
}
