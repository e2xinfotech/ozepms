<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification for both "set your password" (new user) and "reset your password".
 */
class PasswordLinkNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token, private readonly bool $isInvite = false) {}

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
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
        $minutes = config('auth.passwords.users.expire');
        $prefix = $this->isInvite ? 'mail.invite' : 'mail.reset';

        return (new MailMessage)
            ->subject(__($prefix.'.subject', ['app' => config('ozepms.brand.name')]))
            ->greeting(__('mail.greeting', ['name' => $notifiable->name]))
            ->line(__($prefix.'.intro', ['app' => config('ozepms.brand.name')]))
            ->action(__($prefix.'.action'), $url)
            ->line(__('mail.link_expires', ['minutes' => $minutes]))
            ->line(__($prefix.'.outro'));
    }
}
