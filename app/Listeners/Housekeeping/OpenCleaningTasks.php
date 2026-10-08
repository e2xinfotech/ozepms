<?php

namespace App\Listeners\Housekeeping;

use App\Domain\Housekeeping\HousekeepingService;
use App\Domain\Reservations\Events\ReservationCheckedOut;
use App\Models\Property;

/** A checked-out room needs cleaning: opens the task the scheduler e-mails to the responsible person. */
class OpenCleaningTasks
{
    public function __construct(private readonly HousekeepingService $housekeeping) {}

    public function handle(ReservationCheckedOut $event): void
    {
        $property = Property::query()->find($event->reservation->property_id);
        if ($property !== null) {
            $this->housekeeping->openForCheckout($property, $event->roomIds);
        }
    }
}
