<?php

namespace App\Domain\Mail;

use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Sends one logged e-mail through the property's SMTP account; retried a few times when the server is not reachable. */
class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $logId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(EmailService $mail): void
    {
        $log = EmailLog::query()->find($this->logId);
        if ($log === null || $log->status === 'sent') {
            return;
        }
        try {
            $mail->deliver($log);
        } catch (Throwable $e) {
            $mail->failed($log, $e);
            // The sync queue has no retries; a real queue tries again before giving up.
            if (config('queue.default') !== 'sync' && $this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 120);
            }
        }
    }
}
