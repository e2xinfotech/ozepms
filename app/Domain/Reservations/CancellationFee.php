<?php

namespace App\Domain\Reservations;

use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Cancellation / no-show fee from the policy frozen in each room's rate_snapshot.
 *
 * A rule's window starts when less than `hours_before_arrival` hours remain until the
 * arrival time (check-in date + property check-in time, property timezone). Of the rules
 * whose window has started, the one with the smallest window applies (the latest one).
 * Charges: none, first_night, nights (first N nights), percent (of the room total incl. tax),
 * fixed, full. Non-refundable rate plans normally have a "full" rule with a large window.
 */
class CancellationFee
{
    /** @param  'cancellation'|'no_show'  $kind */
    public function forReservation(Reservation $reservation, Property $property, string $kind = 'cancellation', ?CarbonImmutable $now = null): string
    {
        $total = '0';
        foreach ($reservation->rooms as $room) {
            if (in_array($room->status, ['cancelled', 'no_show', 'checked_out'], true)) {
                continue;
            }
            $total = Money::add($total, $this->forRoom($room, $property, $kind, $now));
        }

        return Money::forCurrency($total, (string) $reservation->currency_code);
    }

    public function forRoom(ReservationRoom $room, Property $property, string $kind = 'cancellation', ?CarbonImmutable $now = null): string
    {
        $rules = collect($room->rate_snapshot['cancellation']['rules'] ?? [])
            ->filter(fn ($r) => ($r['applies_to'] ?? 'cancellation') === $kind);
        if ($kind === 'no_show' && $rules->isEmpty()) {
            // No explicit no-show rule: a no-show costs what a cancellation at arrival time would.
            $rules = collect($room->rate_snapshot['cancellation']['rules'] ?? [])->filter(fn ($r) => ($r['applies_to'] ?? 'cancellation') === 'cancellation');
            $hoursLeft = 0.0;
        } else {
            $tz = $property->timezone ?: config('app.timezone');
            $arrival = CarbonImmutable::parse($room->check_in->toDateString().' '.($property->check_in_time ?? '14:00:00'), $tz);
            $hoursLeft = max(0.0, (float) ($now ?? CarbonImmutable::now($tz))->diffInMinutes($arrival, false) / 60);
        }

        $rule = $rules->filter(fn ($r) => $hoursLeft < (int) $r['hours_before_arrival'])
            ->sortBy(fn ($r) => (int) $r['hours_before_arrival'])->first();
        if ($rule === null) {
            return '0.00';
        }

        $nights = $room->relationLoaded('nights') ? $room->nights : $room->nights()->get();
        $nightTotals = $nights->map(fn ($n) => Money::add((string) $n->net_price, (string) $n->tax_amount))->values()->all();
        $grand = (string) $room->grand_total;
        $value = (string) ($rule['charge_value'] ?? '0');

        $fee = match ($rule['charge_type']) {
            'first_night' => $nightTotals[0] ?? '0',
            'nights' => Money::sum(array_slice($nightTotals, 0, max(1, (int) $value))),
            'percent' => Money::percent($grand, $value),
            'fixed' => $value,
            'full' => $grand,
            default => '0',
        };

        return Money::forCurrency(Money::min($fee, $grand), (string) ($room->rate_snapshot['currency'] ?? 'INR'));
    }
}
