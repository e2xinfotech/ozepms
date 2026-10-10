<?php

namespace App\Console\Commands;

use App\Domain\Mail\MailSettings;
use App\Domain\Mail\ReservationMailer;
use App\Models\EmailLog;
use App\Models\Property;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pre-arrival e-mail: every morning (09:00 in the property's time zone) guests arriving within the number of days the
 * hotel chose get one e-mail, once. Runs hourly so each time zone is served at its own 09:00; only for hotels that switched it on.
 */
class EmailsPreArrival extends Command
{
    protected $signature = 'emails:pre-arrival';

    protected $description = 'Send the pre-arrival e-mail to guests arriving soon';

    public function handle(ReservationMailer $mailer, MailSettings $settings): int
    {
        $sent = 0;
        Property::query()->where('status', 'active')->each(function (Property $property) use ($mailer, $settings, &$sent) {
            if (! $settings->eventEnabled($property, 'pre_arrival')) {
                return;
            }
            $now = CarbonImmutable::now($property->timezone ?: config('app.timezone'));
            if ($now->hour < 9) {
                return;
            }
            $today = $now->startOfDay();
            $until = $today->addDays($settings->preArrivalDays($property));
            Reservation::acrossProperties()->where('property_id', $property->id)->where('status', 'confirmed')
                ->whereBetween('check_in', [$today->addDay()->toDateString(), $until->toDateString()])
                ->where('created_at', '<', $today->utc())
                ->orderBy('id')->each(function (Reservation $r) use ($mailer, &$sent) {
                    if (EmailLog::query()->where('reservation_id', $r->id)->where('event', 'pre_arrival')->where('status', '!=', 'failed')->exists() || ! $mailer->allowed($r, true)) {
                        return;
                    }
                    try {
                        $mailer->notify($r, 'pre_arrival') && $sent++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
        });
        $this->info("Pre-arrival e-mails queued: {$sent}");

        return self::SUCCESS;
    }
}
