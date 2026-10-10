<?php

namespace App\Notifications;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail to the person responsible for cleaning: rooms that were checked out and need cleaning. */
class CleaningRequestNotification extends Notification
{
    use Queueable;

    /** @param  list<array{room:string, since:mixed, note:?string}>  $rooms */
    public function __construct(private readonly Property $property, private readonly string $name, private readonly array $rooms) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(\App\Domain\Mail\MailSettings::class)->apply($this->build($notifiable), $this->property);
    }

    private function build(object $notifiable): MailMessage
    {
        $names = array_map(fn ($r) => $r['room'], $this->rooms);
        $count = count($names);
        $mail = (new MailMessage)
            ->subject(trans_choice('housekeeping.mail.subject', $count, ['hotel' => $this->property->name, 'rooms' => implode(', ', $names), 'count' => $count]))
            ->greeting(__('housekeeping.mail.greeting', ['name' => $this->name]))
            ->line(trans_choice('housekeeping.mail.intro', $count, ['hotel' => $this->property->name]));
        foreach ($this->rooms as $r) {
            $line = __('housekeeping.mail.room', ['room' => $r['room']]);
            if (! empty($r['note'])) {
                $line .= ' — '.$r['note'];
            }
            $mail->line($line);
        }

        return $mail->line(__('housekeeping.mail.outro'))->salutation(__('housekeeping.mail.salutation', ['hotel' => $this->property->name]));
    }
}
