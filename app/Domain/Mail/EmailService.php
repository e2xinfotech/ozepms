<?php

namespace App\Domain\Mail;

use App\Domain\Billing\InvoicePdf;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Property;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Every e-mail the PMS sends on its own goes through here: it is written to the log first, then
 * sent by the queue worker (at once when the queue is the sync driver), so nothing is lost when the
 * mail server is down and the hotel can see what was sent and resend what failed.
 */
class EmailService
{
    public function __construct(private readonly MailSettings $settings) {}

    /** @param  list<array{type: string, id: int}>  $attachments  rebuilt when the mail is sent (e.g. the invoice PDF) */
    public function queue(?Property $property, string $event, string $to, ?string $toName, string $subject, string $html, ?int $reservationId = null, ?int $by = null, array $attachments = []): EmailLog
    {
        $log = EmailLog::query()->create([
            'property_id' => $property?->id, 'reservation_id' => $reservationId, 'event' => $event, 'to_email' => $to, 'to_name' => $toName,
            'subject' => mb_substr($subject, 0, 255), 'body_html' => $html, 'attachments' => $attachments ?: null, 'status' => 'queued', 'created_by' => $by,
        ]);
        SendEmailJob::dispatch($log->id);

        return $log->fresh();
    }

    public function resend(EmailLog $log): EmailLog
    {
        $log->forceFill(['status' => 'queued', 'error' => null])->save();
        SendEmailJob::dispatch($log->id);

        return $log->fresh();
    }

    /** Sends a logged e-mail now. Throws when the mail server refuses it. */
    public function deliver(EmailLog $log): void
    {
        $property = $log->property_id ? Property::query()->find($log->property_id) : null;
        $log->forceFill(['attempts' => $log->attempts + 1])->save();
        $this->send($property, $log->to_email, $log->to_name, $log->subject, $log->body_html, $this->files($log));
        $log->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
    }

    public function failed(EmailLog $log, Throwable $e): void
    {
        $log->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        report($e);
    }

    /** Sends a test e-mail through the settings and returns null when it went out, else the reason. */
    public function test(?Property $property, string $to, string $subject, string $html): ?string
    {
        try {
            $this->send($property, $to, null, $subject, $html);

            return null;
        } catch (Throwable $e) {
            return mb_substr($e->getMessage(), 0, 500);
        }
    }

    public function sentAlready(int $reservationId, string $event): bool
    {
        return EmailLog::query()->where('reservation_id', $reservationId)->where('event', $event)->whereIn('status', ['queued', 'sent'])->exists();
    }

    /** @return list<array{name: string, data: string, mime: string}> */
    private function files(EmailLog $log): array
    {
        $files = [];
        foreach ($log->attachments ?? [] as $a) {
            if (($a['type'] ?? '') === 'invoice_pdf' && ($invoice = Invoice::acrossProperties()->find($a['id'] ?? 0)) !== null) {
                $pdf = app(InvoicePdf::class);
                $files[] = ['name' => $pdf->fileName($invoice), 'data' => $pdf->render($invoice), 'mime' => 'application/pdf'];
            }
        }

        return $files;
    }

    /** @param  list<array{name: string, data: string, mime: string}>  $files */
    private function send(?Property $property, string $to, ?string $toName, string $subject, string $html, array $files = []): void
    {
        $r = $this->settings->resolve($property);
        /** @var Mailer $mailer */
        $mailer = Mail::mailer($r['mailer']);
        $mailer->html($html, function ($message) use ($r, $to, $toName, $subject, $files) {
            $message->to($to, $toName)->subject($subject);
            if ($r['on_behalf_address']) {
                $message->from($r['on_behalf_address'], $r['from_name']);
            } elseif ($r['from_address']) {
                $message->from($r['from_address'], $r['from_name']);
            }
            foreach ($files as $f) {
                $message->attachData($f['data'], $f['name'], ['mime' => $f['mime']]);
            }
            if ($r['reply_to']) {
                $message->replyTo($r['reply_to']);
            }
            /** @var Email $symfony */
            $symfony = $message->getSymfonyMessage();
            if ($r['on_behalf_address'] && ($r['from_address'] ?: config('mail.from.address'))) {
                $symfony->sender(new Address((string) ($r['from_address'] ?: config('mail.from.address'))));
            }
            $symfony->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
        });
    }
}
