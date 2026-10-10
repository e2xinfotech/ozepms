<?php

namespace App\Notifications;

use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Short internal e-mail to the contacts set on a room type when a booking includes that room type. */
class RoomBookingAlertNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $roomTypes */
    public function __construct(private readonly Property $property, private readonly Reservation $reservation, private readonly array $roomTypes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(\App\Domain\Mail\MailSettings::class)->apply($this->build($notifiable), $this->property);
    }

    private function build(object $notifiable): MailMessage
    {
        $r = $this->reservation;

        return (new MailMessage)
            ->subject(__('rooms.alert.subject', ['hotel' => $this->property->name, 'ref' => $r->booking_ref]))
            ->line(__('rooms.alert.intro', ['hotel' => $this->property->name]))
            ->line(__('rooms.alert.ref', ['ref' => $r->booking_ref, 'guest' => $r->guest_name]))
            ->line(__('rooms.alert.stay', ['in' => $r->check_in->toDateString(), 'out' => $r->check_out->toDateString()]))
            ->line(__('rooms.alert.rooms', ['rooms' => implode(', ', $this->roomTypes)]));
    }
}
