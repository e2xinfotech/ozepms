<?php

namespace App\Notifications;

use App\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail to platform staff: something is waiting for their decision. */
class ApprovalRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly ApprovalRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('approvals.mail.requested_subject', ['type' => __('approvals.types.'.$this->request->type)]))
            ->greeting(__('approvals.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('approvals.mail.requested_intro', ['type' => __('approvals.types.'.$this->request->type)]))
            ->line($this->request->summary)
            ->action(__('approvals.mail.open'), route('admin.approvals'));
    }
}
