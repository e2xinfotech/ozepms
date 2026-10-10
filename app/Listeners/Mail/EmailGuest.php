<?php

namespace App\Listeners\Mail;

use App\Domain\Mail\MailSettings;
use App\Domain\Mail\ReservationMailer;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationCheckedIn;
use App\Domain\Reservations\Events\ReservationCheckedOut;
use App\Domain\Reservations\Events\ReservationCreated;
use App\Domain\Reservations\Events\ReservationModified;
use App\Models\Reservation;
use Throwable;

/**
 * Tells the guest about their reservation: confirmed, changed, cancelled, thank-you after check-out.
 *
 * Bookings made on the hotel's booking page or through the API are e-mailed by the booking flow itself
 * (it waits for the online payment). Bookings that arrive from a channel (OTA) are only e-mailed when the
 * property switched that on: the OTA already writes to its own guest.
 */
class EmailGuest
{
    public function __construct(private readonly ReservationMailer $mailer, private readonly MailSettings $settings) {}

    public function handleCreated(ReservationCreated $event): void
    {
        $r = $event->reservation;
        if (in_array($r->status, ['confirmed', 'pending'], true) && $this->wanted($r)) {
            $this->run(fn () => $this->mailer->notify($r, 'booking_confirmation', by: $event->by?->id));
        }
    }

    public function handleModified(ReservationModified $event): void
    {
        $r = $event->reservation;
        $changes = $event->changes;
        if (! $this->wanted($r)) {
            return;
        }
        if (($changes['details'] ?? null) === ['status'] && $r->status === 'confirmed') {
            $this->run(fn () => $this->mailer->notify($r, 'booking_confirmation', by: $event->by?->id));

            return;
        }
        if (in_array($r->status, Reservation::OPEN, true) && array_intersect(array_keys($changes), ['dates', 'rooms', 'rates', 'guests'])) {
            $this->run(fn () => $this->mailer->notify($r, 'booking_modification', by: $event->by?->id));
        }
    }

    public function handleCancelled(ReservationCancelled $event): void
    {
        if ($this->wanted($event->reservation, true)) {
            $this->run(fn () => $this->mailer->notify($event->reservation, 'booking_cancellation', by: $event->by?->id));
        }
    }

    public function handleCheckedIn(ReservationCheckedIn $event): void
    {
        if ($this->wanted($event->reservation, true)) {
            $this->run(fn () => $this->mailer->notify($event->reservation, 'check_in_welcome', by: $event->by?->id));
        }
    }

    public function handleCheckedOut(ReservationCheckedOut $event): void
    {
        if ($event->reservation->status === 'checked_out' && $this->wanted($event->reservation, true)) {
            $this->run(fn () => $this->mailer->notify($event->reservation, 'check_out_thanks', by: $event->by?->id));
        }
    }

    private function wanted(Reservation $r, bool $bookingEngineToo = false): bool
    {
        return $this->mailer->allowed($r, $bookingEngineToo);
    }

    private function run(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
