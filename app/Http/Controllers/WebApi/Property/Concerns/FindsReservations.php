<?php

namespace App\Http\Controllers\WebApi\Property\Concerns;

use App\Models\Guest;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public id → model lookups for the reservation endpoints. Every lookup goes through the
 * tenant scope: another property's id is a 404 in the URL and a field error in the body.
 */
trait FindsReservations
{
    protected function reservationOr404(string $id): Reservation
    {
        return Reservation::query()->where('public_id', $id)->firstOrFail();
    }

    protected function roomOr404(Reservation $reservation, string $id): ReservationRoom
    {
        return ReservationRoom::query()->where('reservation_id', $reservation->id)->where('public_id', $id)->firstOrFail();
    }

    protected function guestOr404(string $id): Guest
    {
        return Guest::query()->where('public_id', $id)->whereNull('anonymized_at')->firstOrFail();
    }

    protected function unitField(string $id, string $field): PhysicalUnit
    {
        return PhysicalUnit::query()->where('public_id', $id)->first()
            ?? throw ValidationException::withMessages([$field => __('reservations.errors.unit_not_found')]);
    }

    protected function roomTypeField(string $id, string $field): RoomType
    {
        return RoomType::query()->where('public_id', $id)->first()
            ?? throw ValidationException::withMessages([$field => __('reservations.errors.rate_not_found')]);
    }

    /**
     * Room rows of the form → room specs for ReservationService (products, units, room ids).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function roomSpecs(array $rows, ?Reservation $reservation = null): array
    {
        $specs = [];
        foreach (array_values($rows) as $i => $row) {
            $roomType = $this->roomTypeField($row['room_type_id'], "rooms.$i.room_type_id");
            $plan = RatePlan::query()->where('public_id', $row['rate_plan_id'])->first();
            $product = $plan ? Product::query()->where('room_type_id', $roomType->id)->where('rate_plan_id', $plan->id)->where('is_active', true)->first() : null;
            if ($product === null) {
                throw ValidationException::withMessages(["rooms.$i.rate_plan_id" => __('reservations.errors.rate_not_found')]);
            }
            $product->setRelation('ratePlan', $plan);
            $roomId = null;
            if (! empty($row['id'])) {
                $roomId = $reservation ? ReservationRoom::query()->where('reservation_id', $reservation->id)->where('public_id', $row['id'])->value('id') : null;
                if ($roomId === null) {
                    throw ValidationException::withMessages(["rooms.$i.id" => __('reservations.errors.room_not_found')]);
                }
            }
            $specs[$i] = [
                'id' => $roomId, 'product' => $product, 'check_in' => $row['check_in'], 'check_out' => $row['check_out'],
                'adults' => (int) $row['adults'], 'children' => (int) ($row['children'] ?? 0), 'infants' => (int) ($row['infants'] ?? 0),
                'child_ages' => $row['child_ages'] ?? null, 'rate' => isset($row['rate']) && $row['rate'] !== '' ? (string) $row['rate'] : null,
                'unit' => ! empty($row['unit_id']) ? $this->unitField($row['unit_id'], "rooms.$i.unit_id") : null,
            ];
        }

        return $specs;
    }

    /** Validated form → ReservationService data (create / modify). */
    protected function payload(FormRequest $request, ?Reservation $reservation = null): array
    {
        $v = $request->validated();
        $data = array_intersect_key($v, array_flip(['status', 'arrival_time', 'departure_time', 'purpose', 'market', 'travel_agent', 'company_name', 'channel_ref', 'special_requests', 'internal_notes']));
        if (array_key_exists('source', $v)) {
            $data['source_id'] = $v['source'] === null ? null : (DB::table('booking_sources')->where('code', $v['source'])
                ->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', app(\App\Support\PropertyContext::class)->id()))->value('id')
                ?? throw ValidationException::withMessages(['source' => __('reservations.errors.source_not_found')]));
        }
        if (isset($v['rooms'])) {
            $data['rooms'] = $this->roomSpecs($v['rooms'], $reservation);
        }
        if (! empty($v['guest_id'])) {
            $data['guest_model'] = Guest::query()->where('public_id', $v['guest_id'])->first()
                ?? throw ValidationException::withMessages(['guest_id' => __('reservations.errors.guest_not_found')]);
        }
        if (isset($v['guest'])) {
            $data['guest'] = $v['guest'];
            $data['update_guest'] = ! empty($v['guest_id']) || $reservation !== null;
        }
        if (array_key_exists('companions', $v)) {
            $data['companions'] = $v['companions'] ?? [];
        }

        return $data;
    }
}
