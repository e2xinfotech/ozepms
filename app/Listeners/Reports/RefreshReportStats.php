<?php

namespace App\Listeners\Reports;

use App\Domain\Accommodation\Events\UnitBlockChanged;
use App\Domain\Reports\ReportRollupService;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationCheckedIn;
use App\Domain\Reservations\Events\ReservationCheckedOut;
use App\Domain\Reservations\Events\ReservationCreated;
use App\Domain\Reservations\Events\ReservationModified;
use App\Domain\Reservations\Events\ReservationNoShow;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Keeps the report rollups current: after a booking change (dispatched after commit) the nights of
 * the stay — old and new dates — are rebuilt; after a room block the blocked dates are rebuilt.
 * A failure is logged and never breaks the booking; the nightly `reports:refresh` repairs it.
 */
class RefreshReportStats
{
    public function __construct(private readonly ReportRollupService $rollup) {}

    public function handle(ReservationCreated|ReservationModified|ReservationCancelled|ReservationNoShow|ReservationCheckedIn|ReservationCheckedOut|UnitBlockChanged $event): void
    {
        try {
            if ($event instanceof UnitBlockChanged) {
                $this->rollup->refresh($event->propertyId, $event->from, $event->to->addDay());

                return;
            }
            $r = $event->reservation;
            $dates = [$r->check_in->toDateString(), $r->check_out->toDateString()];
            if ($event instanceof ReservationModified) {
                foreach (['from', 'to'] as $side) {
                    foreach ((array) ($event->changes['dates'][$side] ?? []) as $d) {
                        $dates[] = (string) $d;
                    }
                }
            }
            // Rooms of the booking may have their own dates (multi-room stays).
            foreach ($r->rooms()->get(['check_in', 'check_out']) as $room) {
                $dates[] = $room->check_in->toDateString();
                $dates[] = $room->check_out->toDateString();
            }
            $this->rollup->refresh((int) $r->property_id, CarbonImmutable::parse(min($dates)), CarbonImmutable::parse(max($dates))->addDay());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
