<?php

namespace App\Notifications;

use App\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail to the person who asked: the request was received and waits for a decision. */
class ApprovalSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly ApprovalRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('approvals.mail.submitted_subject', ['type' => __('approvals.types.'.$this->request->type)]))
            ->greeting(__('approvals.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('approvals.mail.submitted_intro', ['summary' => $this->request->summary]));

        return app(\App\Domain\Mail\MailSettings::class)->apply($mail, null);
    }
}
