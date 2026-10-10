<?php

namespace App\Domain\Mail;

use App\Models\MailConfig;
use App\Models\Property;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;

/**
 * Which SMTP account sends the e-mail of a property, and which e-mails it sends.
 *
 * Order: the property's own SMTP account, then the platform default set by the Super Admin,
 * then the server's MAIL_* settings. The password is stored encrypted and never leaves the server.
 */
class MailSettings
{
    /** E-mails to the guest that a property can switch on or off. */
    public const EVENTS = ['booking_confirmation', 'booking_modification', 'booking_cancellation', 'pre_arrival', 'check_in_welcome', 'check_out_thanks', 'payment_receipt', 'invoice_issued'];

    /** E-mails that are off until the hotel switches them on (everything else is on). */
    public const OFF_BY_DEFAULT = ['pre_arrival'];

    /** Words a hotel can put in its own wording; each is replaced with the booking's value when the e-mail is sent. */
    public const PLACEHOLDERS = ['guest_name', 'hotel_name', 'booking_ref', 'check_in', 'check_out', 'nights', 'rooms', 'total', 'paid', 'balance', 'amount', 'invoice_no', 'hotel_phone', 'hotel_email', 'hotel_address', 'booking_link'];

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    public function row(?int $propertyId): ?MailConfig
    {
        $q = MailConfig::query();

        return ($propertyId === null ? $q->whereNull('property_id') : $q->where('property_id', $propertyId))->first();
    }

    /** What the settings screen shows (no password). */
    public function view(?Property $property): array
    {
        $row = $this->row($property?->id);
        $events = [];
        foreach (self::EVENTS as $e) {
            $events[$e] = (bool) (($row?->events ?? [])[$e] ?? ! in_array($e, self::OFF_BY_DEFAULT, true));
        }
        $source = $this->source($property);

        return [
            'host' => $row?->host, 'port' => $row?->port, 'encryption' => $row?->encryption ?? 'tls', 'username' => $row?->username,
            'has_password' => $row !== null && ! empty($row->password), 'from_address' => $row?->from_address, 'from_name' => $row?->from_name,
            'reply_to' => $row?->reply_to, 'events' => $events, 'send_for_channels' => (bool) ($row?->send_for_channels ?? false),
            'on_behalf' => (bool) ($row?->on_behalf ?? true), 'pre_arrival_days' => (int) ($row?->pre_arrival_days ?? 2), 'property_email' => $property?->email,
            'source' => $source, 'sends' => $source !== 'env' || config('mail.default') !== 'log',
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function save(?Property $property, array $data): array
    {
        $row = $this->row($property?->id) ?? new MailConfig(['property_id' => $property?->id]);
        foreach (['host', 'username', 'from_address', 'from_name', 'reply_to'] as $f) {
            if (array_key_exists($f, $data)) {
                $row->{$f} = $data[$f] !== null && trim((string) $data[$f]) !== '' ? trim((string) $data[$f]) : null;
            }
        }
        if (array_key_exists('port', $data)) {
            $row->port = $data['port'] !== null && $data['port'] !== '' ? (int) $data['port'] : null;
        }
        if (isset($data['encryption'])) {
            $row->encryption = $data['encryption'];
        }
        if (! empty($data['password'])) {
            $row->password = $data['password'];
        } elseif (! empty($data['clear_password'])) {
            $row->password = null;
        }
        if (isset($data['events']) && is_array($data['events'])) {
            $row->events = array_map('boolval', array_intersect_key($data['events'], array_flip(self::EVENTS)));
        }
        if (array_key_exists('send_for_channels', $data)) {
            $row->send_for_channels = (bool) $data['send_for_channels'];
        }
        if (array_key_exists('on_behalf', $data)) {
            $row->on_behalf = (bool) $data['on_behalf'];
        }
        if (! empty($data['pre_arrival_days'])) {
            $row->pre_arrival_days = (int) $data['pre_arrival_days'];
        }
        $row->save();
        Mail::purge($this->name($property));

        return $this->view($property);
    }

    public function eventEnabled(Property $property, string $event): bool
    {
        return (bool) (($this->row($property->id)?->events ?? [])[$event] ?? ! in_array($event, self::OFF_BY_DEFAULT, true));
    }

    public function sendForChannels(Property $property): bool
    {
        return (bool) $this->row($property->id)?->send_for_channels;
    }

    public function preArrivalDays(Property $property): int
    {
        return max(1, (int) ($this->row($property->id)?->pre_arrival_days ?? 2));
    }

    /** The hotel's own wording for one e-mail, or null when the default text is used. @return array{subject: string, body: string}|null */
    public function template(Property $property, string $event): ?array
    {
        $t = ($this->row($property->id)?->templates ?? [])[$event] ?? null;

        return is_array($t) && (filled($t['subject'] ?? null) || filled($t['body'] ?? null)) ? ['subject' => (string) ($t['subject'] ?? ''), 'body' => (string) ($t['body'] ?? '')] : null;
    }

    public function saveTemplate(Property $property, string $event, ?string $subject, ?string $body): void
    {
        $row = $this->row($property->id) ?? new MailConfig(['property_id' => $property->id]);
        $all = $row->templates ?? [];
        if (blank($subject) && blank($body)) {
            unset($all[$event]);
        } else {
            $all[$event] = ['subject' => trim((string) $subject), 'body' => trim((string) $body)];
        }
        $row->templates = $all ?: null;
        $row->save();
    }

    /** 'property', 'platform' or 'env': where the SMTP account of this property comes from. */
    public function source(?Property $property): string
    {
        if ($property !== null && filled($this->row($property->id)?->host)) {
            return 'property';
        }

        return filled($this->row(null)?->host) ? 'platform' : 'env';
    }

    /**
     * Registers the SMTP account and returns how to send: mailer name, from, reply-to.
     *
     * @return array{mailer: string, from_address: ?string, from_name: ?string, reply_to: ?string, source: string}
     */
    public function resolve(?Property $property): array
    {
        $source = $this->source($property);
        $row = $source === 'property' ? $this->row($property->id) : ($source === 'platform' ? $this->row(null) : null);
        $own = $property !== null ? $this->row($property->id) : null;
        $mailer = config('mail.default');
        if ($row !== null) {
            $mailer = $this->name($source === 'property' ? $property : null);
            config(["mail.mailers.{$mailer}" => [
                'transport' => 'smtp', 'host' => $row->host, 'port' => $row->port ?: ($row->encryption === 'ssl' ? 465 : 587),
                'username' => $row->username, 'password' => $row->password,
                'scheme' => $row->encryption === 'ssl' ? 'smtps' : 'smtp', 'timeout' => 15,
            ]]);
            Mail::purge($mailer);
        }
        $fromAddress = $row?->from_address ?: config('mail.from.address');
        // On a shared account the hotel's name is the sender name; replies go to the hotel.
        $fromName = $source === 'property' ? ($row?->from_name ?: $property?->name) : ($property?->name ?: ($row?->from_name ?: config('mail.from.name')));

        // Guest e-mails look like they come from the hotel: From is the hotel's address, while the SMTP account
        // stays the real sender (Sender header and envelope) so the mail server accepts and signs it.
        $ownFrom = $source === 'property' && filled($row?->from_address);
        $onBehalf = ($own?->on_behalf ?? true) && filled($property?->email) && ! $ownFrom;

        return [
            'mailer' => $mailer, 'from_address' => $fromAddress, 'from_name' => $fromName,
            'on_behalf_address' => $onBehalf ? (string) $property->email : null,
            'reply_to' => $own?->reply_to ?: $property?->email, 'source' => $source,
        ];
    }

    /** Points a notification at the right SMTP account and sender. */
    public function apply(MailMessage $mail, ?Property $property): MailMessage
    {
        $r = $this->resolve($property);
        $mail->mailer($r['mailer']);
        if ($r['from_address']) {
            $mail->from($r['from_address'], $r['from_name']);
        }
        if ($r['reply_to']) {
            $mail->replyTo($r['reply_to']);
        }

        return $mail;
    }

    private function name(?Property $property): string
    {
        return $property === null ? 'oze_platform' : 'oze_property_'.$property->id;
    }
}
