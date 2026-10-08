<?php

namespace App\Listeners\Accommodation;

use App\Domain\Reservations\Events\ReservationCreated;
use App\Models\Property;
use App\Notifications\RoomBookingAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Sends new bookings to the e-mail addresses set on the booked room types (room type setting "Booking notification e-mails"). */
class NotifyRoomTypeContacts
{
    public function handle(ReservationCreated $event): void
    {
        $r = $event->reservation;
        $types = DB::table('reservation_rooms as rr')->join('room_types as rt', 'rt.id', '=', 'rr.room_type_id')
            ->where('rr.reservation_id', $r->id)->whereNotNull('rt.notification_emails')->where('rt.notification_emails', '!=', '')
            ->get(['rt.name', 'rt.notification_emails']);
        if ($types->isEmpty()) {
            return;
        }
        $property = Property::query()->find($r->property_id);
        $byAddress = [];
        foreach ($types as $t) {
            foreach (array_filter(array_map('trim', explode(',', (string) $t->notification_emails))) as $mail) {
                $byAddress[strtolower($mail)][$t->name] = true;
            }
        }
        foreach ($byAddress as $mail => $names) {
            try {
                Notification::route('mail', $mail)->notify((new RoomBookingAlertNotification($property, $r, array_keys($names)))->locale($property->default_language));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
