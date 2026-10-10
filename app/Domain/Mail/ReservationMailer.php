<?php

namespace App\Domain\Mail;

use App\Domain\BookingEngine\BookingEngineService;
use App\Domain\BookingEngine\BookingPresenter;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Payment;
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
    /** The default look of each e-mail: [subject key, intro key]. */
    private const TEXTS = [
        'confirmed' => ['emails.subject.confirmed', 'emails.intro.confirmed'], 'received' => ['emails.subject.received', 'emails.intro.received'],
        'modified' => ['emails.subject.modified', 'emails.intro.modified'], 'cancelled' => ['emails.subject.cancelled', 'emails.intro.cancelled'],
        'pre_arrival' => ['emails.subject.pre_arrival', 'emails.intro.pre_arrival'], 'welcome' => ['emails.subject.welcome', 'emails.intro.welcome'],
        'thanks' => ['emails.subject.thanks', 'emails.intro.thanks'], 'receipt' => ['emails.subject.receipt', 'emails.intro.receipt'],
        'invoice' => ['emails.subject.invoice', 'emails.invoice.intro'], 'credit' => ['emails.subject.credit_note', 'emails.invoice.intro_credit'],
    ];

    /** The kind (default text) each event uses when nothing is said about the booking's status. */
    private const EVENT_KIND = [
        'booking_confirmation' => 'confirmed', 'booking_modification' => 'modified', 'booking_cancellation' => 'cancelled', 'pre_arrival' => 'pre_arrival',
        'check_in_welcome' => 'welcome', 'check_out_thanks' => 'thanks', 'payment_receipt' => 'receipt', 'invoice_issued' => 'invoice',
    ];

    public function __construct(private readonly EmailService $mail, private readonly MailSettings $settings) {}

    /** Sends the e-mail for an event unless the property switched it off or the guest has no address. */
    public function notify(Reservation $r, string $event, bool $force = false, ?int $by = null): ?EmailLog
    {
        $property = Property::query()->find($r->property_id);
        $guest = $r->primaryGuest;
        if ($property === null || ! filled($guest?->email) || (! $force && ! $this->settings->eventEnabled($property, $event))) {
            return null;
        }
        $kind = $event === 'booking_confirmation' && $r->status !== 'confirmed' ? 'received' : self::EVENT_KIND[$event];

        return $this->withLocale($property, function () use ($r, $property, $guest, $event, $kind, $by) {
            $url = $this->url($property, $r);
            [$subject, $intro] = $this->texts($property, $event, $kind, $this->vars($property, $r, $url), ['ref' => $r->booking_ref, 'hotel' => $property->name]);
            $html = view('emails.reservation', $this->frame($property) + [
                'intro' => $intro, 'guest' => $r->guest_name ?: $guest->first_name, 'rows' => $this->rows($r, $kind),
                'url' => $url, 'contact' => $this->contact($property),
            ])->render();

            return $this->mail->queue($property, $event, (string) $guest->email, $r->guest_name, $subject, $html, $r->id, $by);
        });
    }

    /** Is this reservation's guest to be e-mailed at all? Booking-page bookings are e-mailed by the booking flow; channel bookings only when switched on. */
    public function allowed(Reservation $r, bool $engineToo = false): bool
    {
        $source = $r->source_id ? DB::table('booking_sources')->where('id', $r->source_id)->value('code') : null;
        if ($source === 'booking_engine') {
            // Only guests who were told about the booking hear about its end (not an unpaid hold that simply expired).
            return $engineToo && EmailLog::query()->where('reservation_id', $r->id)->where('event', 'booking_confirmation')->where('status', '!=', 'failed')->exists();
        }
        if ($source === 'ota') {
            $property = Property::query()->find($r->property_id);

            return $property !== null && $this->settings->sendForChannels($property);
        }

        return true;
    }

    /** Receipt for money received on a reservation (desk or online). */
    public function paymentReceipt(Payment $payment, bool $force = false, ?int $by = null): ?EmailLog
    {
        $r = Reservation::query()->where('property_id', $payment->property_id)->find($payment->reservation_id);
        $property = $r ? Property::query()->find($r->property_id) : null;
        $guest = $r?->primaryGuest;
        if ($r === null || $property === null || ! filled($guest?->email) || (! $force && ! $this->settings->eventEnabled($property, 'payment_receipt'))) {
            return null;
        }

        return $this->withLocale($property, function () use ($r, $property, $guest, $payment, $by) {
            $cur = (string) $payment->currency_code;
            $method = __('billing.methods.'.$payment->method);
            $rows = [
                __('emails.labels.ref') => (string) $r->booking_ref,
                __('emails.labels.amount_paid') => Money::display((string) $payment->amount, $cur),
                __('emails.labels.method') => str_starts_with($method, 'billing.') ? (string) $payment->method : $method,
                __('emails.labels.date') => CarbonImmutable::parse($payment->received_at ?? now())->locale(app()->getLocale())->translatedFormat('D, d M Y'),
                __('emails.labels.total') => Money::display((string) $r->grand_total, $cur),
                __('emails.labels.paid') => Money::display((string) $r->paid_total, $cur),
                __('emails.labels.balance') => Money::display((string) $r->balance_due, $cur),
            ];
            $vars = $this->vars($property, $r, null);
            $vars['amount'] = Money::display((string) $payment->amount, $cur);
            [$subject, $intro] = $this->texts($property, 'payment_receipt', 'receipt', $vars, ['ref' => $r->booking_ref, 'hotel' => $property->name]);
            $html = view('emails.reservation', $this->frame($property) + [
                'intro' => $intro, 'guest' => $r->guest_name ?: $guest->first_name, 'rows' => $rows, 'url' => null, 'contact' => $this->contact($property),
            ])->render();

            return $this->mail->queue($property, 'payment_receipt', (string) $guest->email, $r->guest_name, $subject, $html, $r->id, $by);
        });
    }

    /** A tax invoice or credit note, written out in the e-mail from the frozen invoice. $to overrides the guest's address. */
    public function invoice(Invoice $invoice, ?string $to = null, bool $force = false, ?int $by = null): ?EmailLog
    {
        $folio = DB::table('folios')->where('id', $invoice->folio_id)->first(['reservation_id']);
        $r = $folio ? Reservation::query()->where('property_id', $invoice->property_id)->find($folio->reservation_id) : null;
        $property = Property::query()->find($invoice->property_id);
        $address = $to ?: $r?->primaryGuest?->email;
        if ($r === null || $property === null || ! filled($address) || (! $force && ! $this->settings->eventEnabled($property, 'invoice_issued'))) {
            return null;
        }

        return $this->withLocale($property, function () use ($invoice, $r, $property, $address, $by) {
            $s = (array) $invoice->snapshot;
            $credit = ($s['type'] ?? '') === 'credit_note';
            $cur = (string) ($s['currency'] ?? $r->currency_code);
            $vars = $this->vars($property, $r, null);
            $vars['invoice_no'] = (string) $invoice->invoice_no;
            $vars['amount'] = Money::display((string) $invoice->grand_total, $cur);
            [$subject, $intro] = $this->texts($property, 'invoice_issued', $credit ? 'credit' : 'invoice', $vars, ['number' => $invoice->invoice_no, 'hotel' => $property->name]);
            $html = view('emails.invoice', $this->frame($property) + [
                's' => $s, 'credit' => $credit, 'cur' => $cur, 'intro' => $intro, 'guest' => $r->guest_name ?: ($s['parties']['bill_to']['name'] ?? ''), 'contact' => $this->contact($property),
                'money' => fn ($v) => Money::display((string) $v, $cur),
                'fdate' => fn ($d) => CarbonImmutable::parse($d)->locale(app()->getLocale())->translatedFormat('d M Y'),
            ])->render();

            return $this->mail->queue($property, 'invoice', (string) $address, $r->guest_name, $subject, $html, $r->id, $by, [['type' => 'invoice_pdf', 'id' => $invoice->id]]);
        });
    }

    /**
     * The default wording of every e-mail in the property's language, with placeholders in {braces}: what the hotel
     * sees before it writes its own. @return array<string, array{subject: string, body: string}>
     */
    public function defaults(Property $property): array
    {
        return $this->withLocale($property, function () {
            $out = [];
            foreach (self::EVENT_KIND as $event => $kind) {
                [$subjectKey, $introKey] = self::TEXTS[$kind];
                $replace = ['ref' => '{booking_ref}', 'hotel' => '{hotel_name}', 'number' => '{invoice_no}'];
                $out[$event] = ['subject' => (string) __($subjectKey, $replace), 'body' => (string) __($introKey, $replace)];
            }

            return $out;
        });
    }

    /**
     * What a template would look like with made-up booking data. Without $subject/$body the saved (or default) wording is used.
     *
     * @return array{subject: string, html: string}
     */
    public function preview(Property $property, string $event, ?string $subject = null, ?string $body = null): array
    {
        return $this->withLocale($property, function () use ($property, $event, $subject, $body) {
            $kind = self::EVENT_KIND[$event];
            $cur = (string) ($property->currency_code ?: 'INR');
            $in = CarbonImmutable::now()->addDays(2);
            $vars = [
                'guest_name' => 'Alex Morgan', 'hotel_name' => (string) $property->name, 'booking_ref' => 'OZE-SAMPLE', 'check_in' => $in->locale(app()->getLocale())->translatedFormat('D, d M Y'),
                'check_out' => $in->addDays(2)->locale(app()->getLocale())->translatedFormat('D, d M Y'), 'nights' => '2', 'rooms' => 'Deluxe Room', 'total' => Money::display('8400.00', $cur),
                'paid' => Money::display('2000.00', $cur), 'balance' => Money::display('6400.00', $cur), 'amount' => Money::display('2000.00', $cur), 'invoice_no' => 'INV/2627/00001',
                'hotel_phone' => (string) $property->phone, 'hotel_email' => (string) $property->email, 'hotel_address' => $this->address($property), 'booking_link' => '',
            ];
            $custom = $subject !== null || $body !== null ? ['subject' => (string) $subject, 'body' => (string) $body] : $this->settings->template($property, $event);
            [$subjectKey, $introKey] = self::TEXTS[$kind];
            $replace = ['ref' => 'OZE-SAMPLE', 'hotel' => $property->name, 'number' => 'INV/2627/00001'];
            $title = filled($custom['subject'] ?? null) ? $this->fill($custom['subject'], $vars) : (string) __($subjectKey, $replace);
            $intro = filled($custom['body'] ?? null) ? $this->fill($custom['body'], $vars) : (string) __($introKey, $replace);
            $rows = [__('emails.labels.ref') => 'OZE-SAMPLE', __('emails.labels.check_in') => $vars['check_in'], __('emails.labels.check_out') => $vars['check_out'],
                __('emails.labels.nights') => '2', __('emails.labels.rooms') => 'Deluxe Room', __('emails.labels.total') => $vars['total']];
            $html = view('emails.reservation', $this->frame($property) + ['intro' => $intro, 'guest' => 'Alex Morgan', 'rows' => $rows, 'url' => null, 'contact' => $this->contact($property)])->render();

            return ['subject' => (string) preg_replace('/\s+/', ' ', $title), 'html' => $html];
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
            $html = view('emails.test', ['footer' => __('emails.test.footer')] + $this->frame($property) + ['via' => $host ?: config('mail.default')])->render();

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

    /**
     * Subject and opening text: the hotel's own wording when it wrote one, else the default. Single-line subject.
     *
     * @param  array<string, string>  $vars
     * @param  array<string, mixed>  $replace  values for the default translation
     * @return array{0: string, 1: string}
     */
    private function texts(Property $property, string $event, string $kind, array $vars, array $replace): array
    {
        [$subjectKey, $introKey] = self::TEXTS[$kind];
        $custom = $this->settings->template($property, $event);
        $subject = filled($custom['subject'] ?? null) ? $this->fill($custom['subject'], $vars) : (string) __($subjectKey, $replace);
        $intro = filled($custom['body'] ?? null) ? $this->fill($custom['body'], $vars) : (string) __($introKey, $replace);

        return [mb_substr((string) preg_replace('/\s+/', ' ', $subject), 0, 250), $intro];
    }

    /** Replaces {placeholders}; one that is not known stays as written. @param  array<string, string>  $vars */
    private function fill(string $text, array $vars): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $text);
    }

    /** @return array<string, string> */
    private function vars(Property $property, Reservation $r, ?string $url): array
    {
        $date = fn ($d) => CarbonImmutable::parse($d)->locale(app()->getLocale())->translatedFormat('D, d M Y');
        $cur = (string) $r->currency_code;
        $rooms = DB::table('reservation_rooms as rr')->join('room_types as rt', 'rt.id', '=', 'rr.room_type_id')->where('rr.reservation_id', $r->id)
            ->where('rr.status', '!=', 'cancelled')->pluck('rt.name')->unique()->implode(', ');

        return [
            'guest_name' => (string) $r->guest_name, 'hotel_name' => (string) $property->name, 'booking_ref' => (string) $r->booking_ref,
            'check_in' => $date($r->check_in), 'check_out' => $date($r->check_out), 'nights' => (string) (int) $r->nights, 'rooms' => $rooms,
            'total' => Money::display((string) $r->grand_total, $cur), 'paid' => Money::display((string) $r->paid_total, $cur), 'balance' => Money::display((string) $r->balance_due, $cur),
            'amount' => '', 'invoice_no' => '', 'hotel_phone' => (string) $property->phone, 'hotel_email' => (string) $property->email,
            'hotel_address' => $this->address($property), 'booking_link' => (string) $url,
        ];
    }

    private function address(Property $property): string
    {
        return implode(', ', array_filter([$property->address_line1, $property->city]));
    }
}
