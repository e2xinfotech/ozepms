<?php

namespace App\Listeners\Mail;

use App\Models\EmailLog;
use App\Support\PropertyContext;
use Illuminate\Notifications\Events\NotificationSent;
use Symfony\Component\Mime\Email;
use Throwable;

/** Keeps a line in the e-mail log for system e-mails (password links, approvals, housekeeping, room alerts). */
class LogSentNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail' || ! is_object($event->response) || ! is_callable([$event->response, 'getOriginalMessage'])) {
            return;
        }
        $message = $event->response->getOriginalMessage();
        if (! $message instanceof Email) {
            return;
        }
        try {
            $to = $message->getTo()[0] ?? null;
            if ($to === null) {
                return;
            }
            EmailLog::query()->create([
                'property_id' => app(PropertyContext::class)->idOrNull(), 'event' => 'system', 'to_email' => $to->getAddress(), 'to_name' => $to->getName() ?: null,
                'subject' => mb_substr((string) $message->getSubject(), 0, 255), 'body_html' => '', // links such as password resets are never stored
                'status' => 'sent', 'attempts' => 1, 'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
