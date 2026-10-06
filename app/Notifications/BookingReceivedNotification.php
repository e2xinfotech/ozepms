<?php

namespace App\Notifications;

use App\Models\Property;
use App\Models\Reservation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail to the guest after a booking-engine booking: confirmed, or received and waiting (pending). */
class BookingReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Reservation $reservation, private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->reservation;
        $property = Property::query()->find($r->property_id);
        $confirmed = $r->status === 'confirmed';
        $date = fn ($d) => CarbonImmutable::parse($d)->locale(app()->getLocale())->translatedFormat('D, d M Y');
        $mail = (new MailMessage)
            ->subject(__($confirmed ? 'booking.mail.subject_confirmed' : 'booking.mail.subject_received', ['ref' => $r->booking_ref, 'hotel' => $property?->name]))
            ->greeting(__('booking.mail.greeting', ['name' => $r->guest_name]))
            ->line(__($confirmed ? 'booking.mail.intro_confirmed' : 'booking.mail.intro_received', ['hotel' => $property?->name]))
            ->line(__('booking.mail.ref', ['ref' => $r->booking_ref]))
            ->line(__('booking.mail.dates', ['in' => $date($r->check_in), 'out' => $date($r->check_out), 'nights' => (int) $r->nights]))
            ->line(__('booking.mail.total', ['amount' => Money::display((string) $r->grand_total, (string) $r->currency_code)]))
            ->action(__('booking.mail.view'), $this->url);
        if ($property?->phone || $property?->email) {
            $mail->line(__('booking.mail.contact', ['hotel' => $property->name, 'contact' => implode(' · ', array_filter([$property->phone, $property->email]))]));
        }

        return $mail->salutation(__('booking.mail.salutation', ['hotel' => $property?->name]));
    }
}
