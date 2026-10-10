<?php

namespace App\Notifications;

use App\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail to the person who asked: the outcome and the reason given. */
class ApprovalDecidedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly ApprovalRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(\App\Domain\Mail\MailSettings::class)->apply($this->build($notifiable), null);
    }

    private function build(object $notifiable): MailMessage
    {
        $outcome = __('approvals.status.'.$this->request->status);
        $mail = (new MailMessage)
            ->subject(__('approvals.mail.decided_subject', ['type' => __('approvals.types.'.$this->request->type), 'outcome' => $outcome]))
            ->greeting(__('approvals.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('approvals.mail.decided_intro', ['summary' => $this->request->summary, 'outcome' => $outcome]));

        if ($this->request->decision_note) {
            $mail->line(__('approvals.mail.note', ['note' => $this->request->decision_note]));
        }

        return $mail;
    }
}
