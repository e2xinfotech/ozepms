<?php

namespace App\Domain\Mail;

use App\Domain\BookingEngine\BookingEngineService;
use App\Domain\BookingEngine\BookingPresenter;
use App\Models\EmailLog;
use App\Models\Property;
use App\Models\Reservation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The e-mails to a guest about a reservation: confirmed or received, changed, cancelled, thank-you
 * after check-out, and a free-text message written by the hotel. They are written in the language
 * of the property, sent from the property's SMTP account and logged.
 */
class ReservationMailer
{
    public const KINDS = ['booking_confirmation', 'booking_modification', 'booking_cancellation', 'check_out_thanks'];

    public function __construct(private readonly EmailService $mail, private readonly MailSettings $settings) {}

    /** Sends the e-mail for an event unless the property switched it off or the guest has no address. */
    public function notify(Reservation $r, string $event, bool $force = false, ?int $by = null): ?EmailLog
    {
        $property = Property::query()->find($r->property_id);
        $guest = $r->primaryGuest;
        if ($property === null || ! filled($guest?->email) || (! $force && ! $this->settings->eventEnabled($property, $event))) {
            return null;
        }
        $kind = match ($event) {
            'booking_confirmation' => $r->status === 'confirmed' ? 'confirmed' : 'received',
            'booking_modification' => 'modified',
            'booking_cancellation' => 'cancelled',
            default => 'thanks',
        };

        return $this->withLocale($property, function () use ($r, $property, $guest, $event, $kind, $by) {
            $hotel = (string) $property->name;
            $html = view('emails.reservation', $this->frame($property) + [
                'kind' => $kind, 'guest' => $r->guest_name ?: $guest->first_name, 'rows' => $this->rows($r, $kind),
                'url' => $this->url($property, $r), 'contact' => $this->contact($property),
            ])->render();
            $subject = __('emails.subject.'.$kind, ['ref' => $r->booking_ref, 'hotel' => $hotel]);

            return $this->mail->queue($property, $event, (string) $guest->email, $r->guest_name, $subject, $html, $r->id, $by);
        });
    }

    /** A message written by hotel staff to the guest of a reservation or to a guest profile. */
    public function message(Property $property, string $to, ?string $name, string $subject, string $body, ?int $reservationId = null, ?int $by = null): EmailLog
    {
        return $this->withLocale($property, function () use ($property, $to, $name, $subject, $body, $reservationId, $by) {
            $html = view('emails.message', $this->frame($property) + [
                'guest' => $name ?: $to, 'body' => $body, 'contact' => $this->contact($property),
            ])->render();

            return $this->mail->queue($property, 'manual', $to, $name, $subject, $html, $reservationId, $by);
        });
    }

    /** The e-mail sent from the settings screen to check the SMTP account. Returns null when it went out, else the reason. */
    public function test(?Property $property, string $to): ?string
    {
        $locale = $property?->default_language ?: app()->getLocale();

        return $this->inLocale($locale, function () use ($property, $to) {
            $app = (string) config('ozepms.brand.name');
            $via = $this->settings->resolve($property);
            $host = $via['source'] === 'env' ? (string) config('mail.mailers.smtp.host') : (string) $this->settings->row($via['source'] === 'property' ? $property->id : null)?->host;
            $html = view('emails.test', $this->frame($property) + ['via' => $host ?: config('mail.default')])->render();

            return $this->mail->test($property, $to, __('emails.test.subject', ['app' => $app]), $html);
        });
    }

    /** @return array<string, mixed> */
    private function frame(?Property $property): array
    {
        $hotel = (string) ($property?->name ?: config('ozepms.brand.name'));

        return ['hotel' => $hotel, 'tagline' => $property?->tagline, 'footer' => __('emails.footer', ['hotel' => $hotel])];
    }

    private function withLocale(Property $property, callable $fn): mixed
    {
        return $this->inLocale($property->default_language ?: app()->getLocale(), $fn);
    }

    private function inLocale(string $locale, callable $fn): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $fn();
        } finally {
            app()->setLocale($previous);
        }
    }

    /** @return array<string, string> */
    private function rows(Reservation $r, string $kind): array
    {
        $date = fn ($d) => CarbonImmutable::parse($d)->locale(app()->getLocale())->translatedFormat('D, d M Y');
        $rooms = DB::table('reservation_rooms as rr')->join('room_types as rt', 'rt.id', '=', 'rr.room_type_id')->where('rr.reservation_id', $r->id)
            ->where('rr.status', '!=', 'cancelled')->pluck('rt.name')->countBy()->map(fn ($n, $name) => $n > 1 ? "$n × $name" : $name)->values()->implode(', ');
        $rows = [
            __('emails.labels.ref') => (string) $r->booking_ref,
            __('emails.labels.check_in') => $date($r->check_in),
            __('emails.labels.check_out') => $date($r->check_out),
            __('emails.labels.nights') => (string) (int) $r->nights,
        ];
        if ($rooms !== '') {
            $rows[__('emails.labels.rooms')] = $rooms;
        }
        $rows[__('emails.labels.guests')] = __('emails.guests_value', ['adults' => (int) $r->adults, 'children' => (int) $r->children]);
        if ($kind !== 'thanks') {
            $rows[__('emails.labels.total')] = Money::display((string) $r->grand_total, (string) $r->currency_code);
        }
        if ($kind === 'cancelled' && Money::compare((string) $r->cancellation_fee, '0') > 0) {
            $rows[__('emails.labels.fee')] = Money::display((string) $r->cancellation_fee, (string) $r->currency_code);
        }

        return $rows;
    }

    private function url(Property $property, Reservation $r): ?string
    {
        return in_array($r->status, ['cancelled', 'no_show'], true) || ! app(BookingEngineService::class)->isOpen($property)
            ? null : app(BookingPresenter::class)->confirmationUrl($r);
    }

    private function contact(Property $property): ?string
    {
        return implode(' · ', array_filter([$property->phone, $property->email])) ?: null;
    }
}
