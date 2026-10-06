<?php

namespace App\Domain\Reservations;

use App\Domain\Accommodation\UnitNightGuard;
use App\Domain\Audit\AuditLogger;
use App\Domain\Availability\AvailabilityService;
use App\Domain\Guests\GuestService;
use App\Domain\Inventory\Exceptions\NotAvailableException;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StayDates;
use App\Domain\Offers\OfferContext;
use App\Domain\Offers\OfferResult;
use App\Domain\Offers\OfferService;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationCheckedIn;
use App\Domain\Reservations\Events\ReservationCheckedOut;
use App\Domain\Reservations\Events\ReservationCreated;
use App\Domain\Reservations\Events\ReservationModified;
use App\Domain\Reservations\Events\ReservationNoShow;
use App\Infrastructure\Database\Tx;
use App\Models\Guest;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to a reservation goes through here (architecture §9):
 *
 *  create   validate → restrictions (AvailabilityService) → price + tax (StayPricer) → ONE
 *           transaction: inventory (InventoryDelta → InventoryService::reserveMany, fixed lock
 *           order), guest, reservation, rooms, nights, PMS room nights, history, audit.
 *           An idempotency key makes a double submit return the first booking.
 *  modify   full replacement of rooms / dates / rates / guests with re-quote and an inventory
 *           delta (only changed nights are released / reserved).
 *  cancel / noShow / confirm / assignUnit / checkIn / checkOut.
 *
 * Inventory rule: inventory_daily.sold = number of active reservation_room_nights; nights of
 * inquiries, cancelled and no-show rooms are inactive. Events are dispatched after commit.
 *
 * Room spec (create / modify), internal models already resolved by the controller:
 *   ['id' => ?int (existing room, modify), 'product' => Product, 'check_in' => CarbonImmutable,
 *    'check_out' => CarbonImmutable, 'adults' => int, 'children' => int, 'infants' => int,
 *    'child_ages' => ?list<int>, 'rate' => ?string (price per night), 'unit' => ?PhysicalUnit]
 */
class ReservationService
{
    public const MAX_ROOMS = 10;

    /** Reasons from the availability engine that block a booking (inventory is checked by reserve()). */
    private const BLOCKING_REASONS = ['occupancy', 'stop_sell', 'closed', 'cta', 'ctd', 'min_los', 'max_los', 'min_los_arrival', 'cutoff', 'max_advance', 'no_rate'];

    public const DETAIL_FIELDS = ['arrival_time', 'departure_time', 'purpose', 'market', 'travel_agent', 'company_name', 'special_requests', 'internal_notes', 'channel_ref', 'source_id'];

    /** True when the last create() returned an existing booking for the same idempotency key. */
    public bool $replayed = false;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly StayPricer $pricer,
        private readonly OfferService $offerService,
        private readonly InventoryDelta $delta,
        private readonly GuestService $guests,
        private readonly ReferenceNumbers $numbers,
        private readonly CancellationFee $fees,
        private readonly UnitNightGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    // ---------------------------------------------------------------- create

    /**
     * @param  array<string, mixed>  $data  status, source_id, idempotency_key, guest (fields), guest_model (?Guest),
     *                                      companions (list of guest fields), rooms (room specs), detail fields
     */
    public function create(Property $property, array $data, ?User $by = null): Reservation
    {
        $this->replayed = false;
        $key = $data['idempotency_key'] ?? null;
        if ($key !== null && ($existing = $this->byIdempotencyKey($property, $key)) !== null) {
            $this->replayed = true;

            return $existing;
        }

        $status = $data['status'] ?? 'confirmed';
        if (! in_array($status, ['inquiry', 'pending', 'confirmed'], true)) {
            throw ValidationException::withMessages(['status' => __('reservations.errors.invalid_status')]);
        }
        $today = $this->today($property);
        $rooms = $this->normalizeRooms($property, $data['rooms'] ?? []);
        // 'allow_past' is internal (imports, demo data): stays that began before today, priced manually.
        $allowPast = (bool) ($data['allow_past'] ?? false);
        foreach ($rooms as $i => $room) {
            if ($room['check_in']->lessThan($today) && ! $allowPast) {
                throw ValidationException::withMessages(["rooms.$i.check_in" => __('reservations.errors.past_arrival')]);
            }
        }
        $this->assertSellable($property, $rooms, array_keys(array_filter($rooms, fn ($r) => $r['check_in']->greaterThanOrEqualTo($today))), (string) ($data['channel'] ?? 'pms'));
        $context = $allowPast ? null : $this->offerContext($property, $data, null);
        $offers = null;
        $priced = $this->pricer->price($property, $rooms, $context, $offers);
        $this->assertPromo($offers);
        // The form sends the total it showed: a different total (rates or offers changed meanwhile) is never booked silently.
        if (isset($data['quoted_total'])) {
            $total = Money::round(Money::sum(array_column($priced, 'grand_total')));
            if (! Money::equals($total, Money::round((string) $data['quoted_total']))) {
                throw ValidationException::withMessages(['quoted_total' => __('reservations.errors.price_changed', ['amount' => Money::display($total, (string) $property->currency_code)])]);
            }
        }

        try {
            $reservation = Tx::run(function () use ($property, $data, $by, $status, $rooms, $priced, $key, $offers, $context) {
                $holds = $status !== 'inquiry';
                if ($holds) {
                    $this->reserveInventory([], $this->inventoryMap($rooms), $rooms);
                }

                $guest = $this->guests->resolve($property, $data['guest'] ?? [], $data['guest_model'] ?? null, (bool) ($data['update_guest'] ?? false), $by);
                $reservation = new Reservation;
                $reservation->property_id = $property->id;
                $reservation->status = $status;
                $reservation->idempotency_key = $key;
                $reservation->currency_code = $property->currency_code;
                $reservation->created_by = $by?->id;
                $reservation->updated_by = $by?->id;
                $reservation->confirmed_at = $status === 'confirmed' ? now() : null;
                // Online bookings waiting for payment hold the rooms only for a while (booking:expire-holds).
                $reservation->hold_expires_at = ! empty($data['hold_minutes']) && $status === 'pending' ? now()->addMinutes((int) $data['hold_minutes']) : null;
                $reservation->arrival_time = $property->check_in_time;
                $reservation->departure_time = $property->check_out_time;
                $this->fillDetails($reservation, $data);
                $reservation->source_id ??= $this->defaultSourceId();
                $this->applyGuest($reservation, $guest);
                $this->applyTotals($reservation, $rooms, $priced);
                $reservation->booking_ref = $this->numbers->bookingRef($property->id, (int) $this->today($property)->year);
                $reservation->save();

                $sort = 0;
                $roomIds = [];
                foreach ($rooms as $rk => $spec) {
                    $room = $this->newRoom($reservation, $spec, $priced[$rk], $sort++, $status === 'inquiry' ? 'pending' : $status);
                    $roomIds[$rk] = $room->id;
                    $this->writeNights($room, $priced[$rk]['nights'], $holds);
                    if ($spec['unit'] !== null) {
                        $this->writeUnitNights($room, $spec['unit'], $room->check_in, $room->check_out, "rooms.$rk.unit_id");
                    }
                }
                if ($offers !== null) {
                    $this->offerService->redeem($this->writeOffers($reservation, $roomIds, $offers, $context));
                }

                $this->linkGuests($reservation, $guest, $data['companions'] ?? [], $property, $by);
                $this->history($reservation, null, $status, $by, null);
                $this->audit->log('reservation.created', $reservation, ['after' => [
                    'ref' => $reservation->booking_ref, 'status' => $status, 'check_in' => $reservation->check_in->toDateString(),
                    'check_out' => $reservation->check_out->toDateString(), 'rooms' => $reservation->room_count, 'total' => (string) $reservation->grand_total,
                ]], $property->id, $by?->id);

                return $reservation;
            });
        } catch (QueryException $e) {
            // A parallel request with the same idempotency key won the race: return its booking.
            if ($key !== null && (int) ($e->errorInfo[1] ?? 0) === 1062 && ($existing = $this->byIdempotencyKey($property, $key)) !== null) {
                $this->replayed = true;

                return $existing;
            }
            throw $e;
        }

        event(new ReservationCreated($reservation->fresh(), $by));

        return $reservation;
    }

    // ---------------------------------------------------------------- modify

    /**
     * Replaces rooms, dates, rates and guest details. Rooms of the reservation missing from
     * $data['rooms'] are cancelled; rooms without 'id' are added.
     */
    public function modify(Reservation $reservation, array $data, ?User $by = null): Reservation
    {
        $property = $this->propertyOf($reservation);
        if (! $reservation->isOpen()) {
            throw ValidationException::withMessages(['status' => __('reservations.errors.not_editable', ['status' => __('reservations.status.'.$reservation->status)])]);
        }
        $reservation->loadMissing(['rooms.nights', 'rooms.unitNights']);
        $existing = $reservation->rooms->keyBy('id');
        $today = $this->today($property);
        $changes = [];

        $rooms = [];
        $check = [];
        if (array_key_exists('rooms', $data)) {
            $rooms = $this->normalizeRooms($property, $data['rooms']);
            foreach ($rooms as $i => &$spec) {
                $old = $spec['id'] !== null ? $existing->get($spec['id']) : null;
                if ($spec['id'] !== null && $old === null) {
                    throw ValidationException::withMessages(["rooms.$i.id" => __('reservations.errors.room_not_found')]);
                }
                if ($old !== null && in_array($old->status, ['cancelled', 'no_show', 'checked_out'], true)) {
                    // Finished rooms cannot change; keep them as they are.
                    unset($rooms[$i]);

                    continue;
                }
                $sameProduct = $old !== null && (int) $old->product_id === (int) $spec['product']->id;
                $sameGuests = $old !== null && (int) $old->adults === $spec['adults'] && (int) $old->children === $spec['children'] && (int) $old->infants === $spec['infants'];
                if ($old !== null && $old->status === 'checked_in') {
                    if (! $old->check_in->equalTo($spec['check_in']) || ! $sameProduct) {
                        throw ValidationException::withMessages(["rooms.$i.check_in" => __('reservations.errors.in_house_locked')]);
                    }
                    if ($spec['check_out']->lessThanOrEqualTo($today) && $spec['check_out']->lessThan($old->check_out)) {
                        throw ValidationException::withMessages(["rooms.$i.check_out" => __('reservations.errors.checkout_past')]);
                    }
                } elseif (($old === null || ! $old->check_in->equalTo($spec['check_in'])) && $spec['check_in']->lessThan($today)) {
                    throw ValidationException::withMessages(["rooms.$i.check_in" => __('reservations.errors.past_arrival')]);
                }
                if ($old === null || ! $sameProduct || ! $old->check_in->equalTo($spec['check_in']) || ! $old->check_out->equalTo($spec['check_out'])) {
                    if ($spec['check_in']->greaterThanOrEqualTo($today) && ($old === null || ! $sameProduct || ! $old->check_in->equalTo($spec['check_in']))) {
                        $check[] = $i;
                    }
                }
                // Keep the prices of nights that stay the same (same product and guests, no manual rate).
                if ($old !== null && $sameProduct && $sameGuests && $spec['rate'] === null) {
                    $spec['keep'] = $this->keptPrices($old);
                }
                $spec['old'] = $old;
            }
            unset($spec);
            if ($rooms === [] && $existing->whereNotIn('status', ['cancelled', 'no_show', 'checked_out'])->isNotEmpty()) {
                throw ValidationException::withMessages(['rooms' => __('reservations.errors.rooms_required')]);
            }
            $this->assertSellable($property, $rooms, $check);
        }

        $offers = null;
        $context = $rooms === [] ? null : $this->offerContext($property, $data, $reservation);
        // A changed promo code re-works the offers of the kept nights too (their old discounts go).
        $reoffer = $context !== null && array_key_exists('promo_code', $data) && $context->promo() !== $this->bookedPromo($reservation);
        if ($reoffer) {
            foreach ($rooms as &$spec) {
                if (! empty($spec['keep'])) {
                    $spec['keep'] = array_map(fn ($k) => ['price' => Money::add($k['base_price'], $k['occupancy_adjust']), 'discount' => '0'] + $k, $spec['keep']);
                    $spec['reoffer'] = true;
                }
            }
            unset($spec);
        }
        $priced = $rooms === [] ? [] : $this->pricer->price($property, $rooms, $context, $offers);
        $this->assertPromo($offers);
        $holds = $reservation->holdsInventory();

        $result = Tx::run(function () use ($reservation, $data, $by, $rooms, $priced, $existing, $holds, $property, $offers, $context, &$changes) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if ($locked === null || $locked->updated_at?->ne($reservation->updated_at)) {
                throw ValidationException::withMessages(['reservation' => __('reservations.errors.changed_meanwhile')]);
            }
            $before = $reservation->only(['check_in', 'check_out', 'grand_total', 'adults', 'children', 'primary_guest_id']);

            if (array_key_exists('rooms', $data)) {
                $keptIds = collect($rooms)->pluck('id')->filter()->all();
                $openOld = $existing->whereNotIn('status', ['cancelled', 'no_show', 'checked_out']);
                $removed = $openOld->filter(fn ($r) => ! in_array($r->id, $keptIds, true));

                // Inventory delta between the old active nights and the new stay.
                if ($holds) {
                    $beforeMap = [];
                    foreach ($openOld as $old) {
                        foreach ($old->nights->where('is_active', true) as $n) {
                            $beforeMap[$old->room_type_id][$n->stay_date->toDateString()] = ($beforeMap[$old->room_type_id][$n->stay_date->toDateString()] ?? 0) + 1;
                        }
                    }
                    $this->reserveInventory($beforeMap, $this->inventoryMap($rooms), $rooms);
                }

                foreach ($removed as $old) {
                    $this->closeRoom($old, 'cancelled', $by);
                    $changes['rooms']['removed'][] = $old->id;
                }

                $sort = 0;
                $roomIds = [];
                foreach ($rooms as $key => $spec) {
                    $old = $spec['old'];
                    $roomIds[$key] = $old?->id;
                    if ($old === null) {
                        $room = $this->newRoom($reservation, $spec, $priced[$key], $sort, $reservation->status === 'checked_in' ? 'confirmed' : ($reservation->status === 'inquiry' ? 'pending' : $reservation->status));
                        $roomIds[$key] = $room->id;
                        $this->writeNights($room, $priced[$key]['nights'], $holds);
                        if ($spec['unit'] !== null) {
                            $this->writeUnitNights($room, $spec['unit'], $room->check_in, $room->check_out, "rooms.$key.unit_id");
                        }
                        $changes['rooms']['added'][] = $room->id;
                    } else {
                        $this->updateRoom($old, $spec, $priced[$key], $sort, $holds, (string) $key, $changes);
                    }
                    $sort++;
                }
                if ($offers !== null) {
                    $this->replaceOffers($reservation, $rooms, $roomIds, $removed->pluck('id')->all(), $offers, $context);
                }
            }

            $reservation->unsetRelation('rooms');
            $this->fillDetails($reservation, $data);
            if (isset($data['guest']) || isset($data['guest_model'])) {
                $guest = $this->guests->resolve($property, $data['guest'] ?? [], $data['guest_model'] ?? $reservation->primaryGuest, true, $by);
                $this->applyGuest($reservation, $guest);
                $this->linkGuests($reservation, $guest, $data['companions'] ?? null, $property, $by);
            }
            $this->recalculate($reservation);
            $reservation->updated_by = $by?->id;

            $after = $reservation->only(['check_in', 'check_out', 'grand_total', 'adults', 'children', 'primary_guest_id']);
            if (! $before['check_in']->equalTo($after['check_in']) || ! $before['check_out']->equalTo($after['check_out'])) {
                $changes['dates'] = [
                    'from' => ['check_in' => $before['check_in']->toDateString(), 'check_out' => $before['check_out']->toDateString()],
                    'to' => ['check_in' => $after['check_in']->toDateString(), 'check_out' => $after['check_out']->toDateString()],
                ];
            }
            if (! Money::equals((string) $before['grand_total'], (string) $after['grand_total'])) {
                $changes['rates'] = ['from' => (string) $before['grand_total'], 'to' => (string) $after['grand_total']];
            }
            foreach (['adults', 'children', 'primary_guest_id'] as $f) {
                if ((int) $before[$f] !== (int) $after[$f]) {
                    $changes['guests'][$f === 'primary_guest_id' ? 'primary_guest' : $f] = [$before[$f], $after[$f]];
                }
            }
            $details = array_values(array_intersect(array_keys($reservation->getDirty()), self::DETAIL_FIELDS));
            if ($details !== []) {
                $changes['details'] = $details;
            }
            $reservation->save();
            if ($changes !== []) {
                $this->audit->log('reservation.modified', $reservation, ['changes' => $changes], $reservation->property_id, $by?->id);
            }

            return $reservation;
        });

        if ($changes !== []) {
            event(new ReservationModified($result->fresh(), $by, $changes));
        }

        return $result;
    }

    // ---------------------------------------------------------------- status changes

    /** Cancels the whole reservation; the fee from the frozen policy goes to billing via the event. */
    public function cancel(Reservation $reservation, ?string $reason = null, ?User $by = null, bool $waiveFee = false): Reservation
    {
        if (! in_array($reservation->status, ['inquiry', 'hold', 'pending', 'confirmed'], true)) {
            throw ValidationException::withMessages(['status' => __('reservations.errors.cannot_cancel', ['status' => __('reservations.status.'.$reservation->status)])]);
        }
        $property = $this->propertyOf($reservation);
        $reservation->loadMissing('rooms.nights');
        $fee = $waiveFee || $reservation->status === 'inquiry' ? '0.00' : $this->fees->forReservation($reservation, $property, 'cancellation');

        $reservation = $this->finish($reservation, 'cancelled', $by, $reason, function (Reservation $r) use ($fee, $reason) {
            $r->cancelled_at = now();
            $r->cancel_reason = $reason;
            $r->cancellation_fee = $fee;
        });
        event(new ReservationCancelled($reservation->fresh(), $by, $fee, $reason));

        return $reservation;
    }

    public function noShow(Reservation $reservation, ?User $by = null, bool $waiveFee = false): Reservation
    {
        $property = $this->propertyOf($reservation);
        if (! in_array($reservation->status, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages(['status' => __('reservations.errors.cannot_no_show')]);
        }
        if ($reservation->check_in->greaterThan($this->today($property))) {
            throw ValidationException::withMessages(['status' => __('reservations.errors.no_show_future')]);
        }
        $reservation->loadMissing('rooms.nights');
        $fee = $waiveFee ? '0.00' : $this->fees->forReservation($reservation, $property, 'no_show');

        $reservation = $this->finish($reservation, 'no_show', $by, null, function (Reservation $r) use ($fee) {
            $r->cancellation_fee = $fee;
        });
        event(new ReservationNoShow($reservation->fresh(), $by, $fee));

        return $reservation;
    }

    /** Inquiry / pending → confirmed. An inquiry takes its inventory now. */
    public function confirm(Reservation $reservation, ?User $by = null): Reservation
    {
        if (! in_array($reservation->status, ['inquiry', 'hold', 'pending'], true)) {
            throw ValidationException::withMessages(['status' => __('reservations.errors.cannot_confirm')]);
        }
        $from = $reservation->status;
        $reservation->loadMissing('rooms.nights');

        Tx::run(function () use ($reservation, $from, $by) {
            if ($from === 'inquiry') {
                $after = [];
                foreach ($reservation->rooms->where('status', '!=', 'cancelled') as $room) {
                    foreach ($room->nights as $n) {
                        $after[$room->room_type_id][$n->stay_date->toDateString()] = ($after[$room->room_type_id][$n->stay_date->toDateString()] ?? 0) + 1;
                    }
                }
                $this->reserveInventory([], $after, []);
                DB::table('reservation_room_nights')->whereIn('reservation_room_id', $reservation->rooms->where('status', '!=', 'cancelled')->pluck('id'))->update(['is_active' => 1]);
            }
            ReservationRoom::query()->where('reservation_id', $reservation->id)->whereIn('status', ['hold', 'pending'])->update(['status' => 'confirmed', 'updated_at' => now()]);
            $reservation->status = 'confirmed';
            $reservation->confirmed_at = now();
            $reservation->hold_expires_at = null;
            $reservation->updated_by = $by?->id;
            $reservation->save();
            $this->history($reservation, $from, 'confirmed', $by, null);
            $this->audit->log('reservation.confirmed', $reservation, ['before' => ['status' => $from], 'after' => ['status' => 'confirmed']], $reservation->property_id, $by?->id);
        });
        event(new ReservationModified($reservation->fresh(), $by, ['details' => ['status']]));

        return $reservation;
    }

    /**
     * Assigns (or moves to) a PMS room for the rest of the stay: all nights before check-in,
     * from today on for an in-house room. $unit = null removes the assignment (not in-house).
     */
    public function assignUnit(Reservation $reservation, ReservationRoom $room, ?PhysicalUnit $unit, ?User $by = null): ReservationRoom
    {
        $property = $this->propertyOf($reservation);
        if ((int) $room->reservation_id !== (int) $reservation->id || ! $reservation->isOpen() || in_array($room->status, ['cancelled', 'no_show', 'checked_out'], true)) {
            throw ValidationException::withMessages(['unit_id' => __('reservations.errors.not_assignable')]);
        }
        if ($unit !== null && (int) $unit->room_type_id !== (int) $room->room_type_id) {
            throw ValidationException::withMessages(['unit_id' => __('reservations.errors.unit_wrong_type')]);
        }
        if ($unit === null && $room->status === 'checked_in') {
            throw ValidationException::withMessages(['unit_id' => __('reservations.errors.in_house_needs_room')]);
        }
        $today = $this->today($property);
        $from = $room->status === 'checked_in' ? $today->max($room->check_in) : $room->check_in;
        if ($from->greaterThanOrEqualTo($room->check_out)) {
            throw ValidationException::withMessages(['unit_id' => __('reservations.errors.not_assignable')]);
        }
        $previous = $this->unitOf($room);

        Tx::run(function () use ($room, $unit, $from, $reservation, $by, $previous) {
            DB::table('unit_nights')->where('reservation_room_id', $room->id)->where('stay_date', '>=', $from->toDateString())->delete();
            if ($unit !== null) {
                $this->writeUnitNights($room, $unit, $from, $room->check_out, 'unit_id');
            }
            $reservation->updated_by = $by?->id;
            $reservation->touch();
            $this->audit->log($previous ? 'reservation.room_moved' : 'reservation.room_assigned', $reservation, [
                'before' => ['unit' => $previous?->name], 'after' => ['unit' => $unit?->name, 'from' => $from->toDateString()],
            ], $reservation->property_id, $by?->id);
        });

        if ($previous !== null && $unit !== null && $previous->id !== $unit->id) {
            event(new ReservationModified($reservation->fresh(), $by, ['rooms' => ['moved' => [[$room->id, $previous->id, $unit->id]]]]));
        }

        return $room;
    }

    /**
     * Checks rooms in (all open rooms arriving by today when $roomIds is null). Every room needs a
     * PMS room assigned for tonight that is not dirty. $guestData may update the primary guest's
     * ID details (guest verification at the desk).
     *
     * @param  list<int>|null  $roomIds
     */
    public function checkIn(Reservation $reservation, ?array $roomIds = null, ?User $by = null, array $guestData = [], ?string $note = null): Reservation
    {
        $property = $this->propertyOf($reservation);
        if (! in_array($reservation->status, ['pending', 'confirmed', 'checked_in'], true)) {
            throw ValidationException::withMessages(['status' => __('reservations.errors.cannot_check_in', ['status' => __('reservations.status.'.$reservation->status)])]);
        }
        $today = $this->today($property);
        $reservation->loadMissing('rooms');
        $rooms = $reservation->rooms->whereIn('status', ['pending', 'confirmed'])
            ->when($roomIds !== null, fn ($c) => $c->whereIn('id', $roomIds));
        if ($rooms->isEmpty()) {
            throw ValidationException::withMessages(['rooms' => __('reservations.errors.nothing_to_check_in')]);
        }
        foreach ($rooms as $room) {
            if ($room->check_in->greaterThan($today)) {
                throw ValidationException::withMessages(['rooms' => __('reservations.errors.early_check_in', ['date' => $room->check_in->toDateString()])]);
            }
            if ($room->check_out->lessThanOrEqualTo($today)) {
                throw ValidationException::withMessages(['rooms' => __('reservations.errors.stay_over')]);
            }
            $unitId = DB::table('unit_nights')->where('reservation_room_id', $room->id)->where('stay_date', $today->toDateString())->value('unit_id');
            if ($unitId === null) {
                throw ValidationException::withMessages(['unit_id' => __('reservations.errors.assign_first')]);
            }
            $unit = PhysicalUnit::query()->withTrashed()->find($unitId);
            if ($unit !== null && $unit->housekeeping_status === 'dirty') {
                throw ValidationException::withMessages(['unit_id' => __('reservations.errors.room_not_ready', ['room' => $unit->name])]);
            }
        }

        Tx::run(function () use ($reservation, $rooms, $by, $guestData, $note, $property) {
            foreach ($rooms as $room) {
                $from = $room->status;
                $room->status = 'checked_in';
                $room->checked_in_at = now();
                $room->checked_in_by = $by?->id;
                $room->save();
                $this->history($reservation, $from, 'checked_in', $by, $note, $room->id);
            }
            if ($guestData !== [] && $reservation->primaryGuest !== null) {
                $this->guests->resolve($property, $guestData, $reservation->primaryGuest, true, $by);
            }
            $from = $reservation->status;
            if ($from !== 'checked_in') {
                $reservation->status = 'checked_in';
                $reservation->confirmed_at ??= now();
                $this->history($reservation, $from, 'checked_in', $by, $note);
            }
            $reservation->updated_by = $by?->id;
            $reservation->save();
            $this->audit->log('reservation.checked_in', $reservation, ['after' => ['rooms' => $rooms->pluck('id')->all()]], $reservation->property_id, $by?->id);
        });
        event(new ReservationCheckedIn($reservation->fresh(), $by, $rooms->pluck('id')->values()->all()));

        return $reservation;
    }

    /**
     * Checks rooms out. With an open balance (billing's FolioService::summary) check-out is
     * refused unless $overrideBalance (permission checkout.override_balance). Leaving before the
     * booked departure shortens the stay: later nights are released and repriced.
     *
     * @param  list<int>|null  $roomIds
     */
    public function checkOut(Reservation $reservation, ?array $roomIds = null, ?User $by = null, bool $overrideBalance = false, ?string $note = null): Reservation
    {
        $property = $this->propertyOf($reservation);
        $reservation->loadMissing(['rooms.nights']);
        $rooms = $reservation->rooms->where('status', 'checked_in')->when($roomIds !== null, fn ($c) => $c->whereIn('id', $roomIds));
        if ($reservation->status !== 'checked_in' || $rooms->isEmpty()) {
            throw ValidationException::withMessages(['status' => __('reservations.errors.cannot_check_out')]);
        }
        $remaining = $reservation->rooms->whereNotIn('status', ['cancelled', 'no_show', 'checked_out'])->whereNotIn('id', $rooms->pluck('id'));
        $today = $this->today($property);
        $balance = $this->balance($reservation, $today->toDateString());
        if ($remaining->isEmpty() && $balance !== null && Money::isPositive($balance) && ! $overrideBalance) {
            throw ValidationException::withMessages(['balance' => __('reservations.errors.balance_due', ['amount' => Money::display($balance, (string) $reservation->currency_code)])]);
        }
        $changes = [];

        Tx::run(function () use ($reservation, $rooms, $remaining, $by, $note, $today, &$changes) {
            foreach ($rooms as $room) {
                // Early departure: release and deactivate the nights from today on (at least one night stays).
                $cut = $today->max($room->check_in->addDay());
                if ($cut->lessThan($room->check_out)) {
                    $old = $room->check_out;
                    $this->delta->apply([$room->room_type_id => array_fill_keys(StayDates::nights($cut, $old), 1)], []);
                    DB::table('reservation_room_nights')->where('reservation_room_id', $room->id)->where('stay_date', '>=', $cut->toDateString())->update(['is_active' => 0]);
                    DB::table('unit_nights')->where('reservation_room_id', $room->id)->where('stay_date', '>=', $cut->toDateString())->delete();
                    $room->check_out = $cut;
                    $this->sumRoom($room);
                    $changes['dates'] = ['to' => ['check_out' => $cut->toDateString()], 'from' => ['check_out' => $old->toDateString()]];
                }
                $unitId = DB::table('unit_nights')->where('reservation_room_id', $room->id)->orderByDesc('stay_date')->value('unit_id');
                if ($unitId !== null) {
                    PhysicalUnit::query()->whereKey($unitId)->update(['housekeeping_status' => 'dirty', 'updated_at' => now()]);
                }
                $room->status = 'checked_out';
                $room->checked_out_at = now();
                $room->checked_out_by = $by?->id;
                $room->save();
                $this->history($reservation, 'checked_in', 'checked_out', $by, $note, $room->id);
            }
            $reservation->unsetRelation('rooms');
            $this->recalculate($reservation);
            if ($remaining->isEmpty()) {
                $reservation->status = 'checked_out';
                $this->history($reservation, 'checked_in', 'checked_out', $by, $note);
            }
            $reservation->updated_by = $by?->id;
            $reservation->save();
            $this->audit->log('reservation.checked_out', $reservation, ['after' => ['rooms' => $rooms->pluck('id')->all()]], $reservation->property_id, $by?->id);
        });

        if (isset($changes['dates'])) {
            event(new ReservationModified($reservation->fresh(), $by, $changes));
        }
        event(new ReservationCheckedOut($reservation->fresh(), $by, $rooms->pluck('id')->values()->all()));

        return $reservation;
    }

    public function addNote(Reservation $reservation, string $body, ?User $by = null): \App\Models\Note
    {
        $note = \App\Models\Note::query()->create([
            'property_id' => $reservation->property_id, 'subject_type' => 'reservation', 'subject_id' => $reservation->id,
            'body' => mb_substr(trim($body), 0, 2000), 'user_id' => $by?->id, 'created_at' => now(),
        ]);
        $this->audit->log('reservation.note_added', $reservation, [], $reservation->property_id, $by?->id);

        return $note;
    }

    /**
     * Open balance from billing, or null while billing is not installed. With $departingOn: the
     * balance when checking out that day (later nights are not charged).
     */
    public function balance(Reservation $reservation, ?string $departingOn = null): ?string
    {
        $class = 'App\\Domain\\Billing\\FolioService';
        if (! class_exists($class) || ! method_exists($class, 'summary')) {
            return null;
        }
        if ($departingOn !== null && method_exists($class, 'checkoutBalance')) {
            return (string) app($class)->checkoutBalance($reservation, $departingOn);
        }
        $summary = app($class)->summary($reservation);

        return isset($summary['balance']) ? (string) $summary['balance'] : null;
    }

    // ---------------------------------------------------------------- queries used by pages

    /** The PMS room assigned for the first remaining night of the stay (tonight for in-house). */
    public function unitOf(ReservationRoom $room): ?PhysicalUnit
    {
        $id = DB::table('unit_nights')->where('reservation_room_id', $room->id)->orderBy('stay_date')->value('unit_id');

        return $id ? PhysicalUnit::query()->withTrashed()->find($id) : null;
    }

    /**
     * Active PMS rooms of a room type free for every night of [from, to): no other guest and
     * no open block. $exceptRoomId: nights of that reservation room do not count (moves).
     *
     * @return \Illuminate\Support\Collection<int, PhysicalUnit>
     */
    public function freeUnits(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptRoomId = null)
    {
        return PhysicalUnit::query()->where('room_type_id', $roomTypeId)->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->from('unit_nights')->whereColumn('unit_nights.unit_id', 'physical_units.id')
                ->where('unit_nights.stay_date', '>=', $from->toDateString())->where('unit_nights.stay_date', '<', $to->toDateString())
                ->when($exceptRoomId, fn ($w) => $w->where(fn ($x) => $x->whereNull('unit_nights.reservation_room_id')->orWhere('unit_nights.reservation_room_id', '!=', $exceptRoomId))))
            ->whereNotExists(fn ($q) => $q->from('unit_blocks')->whereColumn('unit_blocks.unit_id', 'physical_units.id')
                ->whereNull('unit_blocks.released_at')->whereIn('unit_blocks.block_type', InventoryService::BLOCKING_TYPES)
                ->where('unit_blocks.start_date', '<', $to->toDateString())->where('unit_blocks.end_date', '>', $from->toDateString()))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Night prices of an existing room that stay valid when only its dates change.
     *
     * @return array<string, array{price: string, base_price: string, occupancy_adjust: string, discount: string}>
     */
    public function keptPrices(ReservationRoom $room): array
    {
        $nights = $room->relationLoaded('nights') ? $room->nights : $room->nights()->get();

        return $nights->where('is_active', true)->mapWithKeys(fn ($n) => [
            $n->stay_date->toDateString() => [
                'price' => Money::sub(Money::add((string) $n->base_price, (string) $n->occupancy_adjust), (string) $n->discount),
                'base_price' => (string) $n->base_price, 'occupancy_adjust' => (string) $n->occupancy_adjust, 'discount' => (string) $n->discount,
            ],
        ])->all();
    }

    // ---------------------------------------------------------------- offers

    /**
     * Offer context of a booking: booked today (a modification keeps the original booking date),
     * on the front desk unless told otherwise, with the entered promo code (a modification keeps
     * the code it was booked with unless 'promo_code' is sent), booking source and guest country.
     */
    public function offerContext(Property $property, array $data, ?Reservation $reservation): OfferContext
    {
        $tz = $property->timezone ?: config('app.timezone');
        $bookedOn = $reservation?->created_at ? CarbonImmutable::parse($reservation->created_at)->setTimezone($tz)->startOfDay() : $this->today($property);
        $promo = array_key_exists('promo_code', $data) ? $data['promo_code'] : ($reservation ? $this->bookedPromo($reservation) : null);
        $sourceId = $data['source_id'] ?? $reservation?->source_id;
        $source = $sourceId ? DB::table('booking_sources')->where('id', $sourceId)->value('code') : 'direct';
        $guest = $data['guest_model'] ?? ($reservation?->primary_guest_id ? Guest::query()->find($reservation->primary_guest_id) : null);
        $country = $data['guest']['nationality_iso2'] ?? $data['guest']['country_iso2'] ?? $guest?->nationality_iso2 ?? $guest?->country_iso2 ?? null;

        return new OfferContext($bookedOn, (string) ($data['channel'] ?? 'pms'), $promo, $source ? (string) $source : null, $country ? (string) $country : null);
    }

    /** The promo code a reservation was booked with (from its frozen offer applications). */
    public function bookedPromo(Reservation $reservation): ?string
    {
        foreach (DB::table('offer_applications')->where('reservation_id', $reservation->id)->pluck('snapshot') as $snap) {
            $code = json_decode((string) $snap, true)['entered_code'] ?? null;
            if ($code) {
                return (string) $code;
            }
        }

        return null;
    }

    /** An entered promo code that does not apply is a field error with the reason. */
    private function assertPromo(?OfferResult $offers): void
    {
        $promo = $offers?->promo;
        if ($promo !== null && $promo['status'] !== 'applied') {
            throw ValidationException::withMessages(['promo_code' => __($promo['reason'] ?? 'offers.reasons.unknown_code')]);
        }
    }

    /**
     * Freezes the applied offers on the booking (offer_applications, one row per room and offer,
     * snapshot = offer terms + nightly amounts). $carried: [room id][offer id][date => amount] of
     * kept nights (modify). Returns the ids of the offers now on the booking.
     *
     * @param  array<int|string, int>  $roomIds  room key => reservation_rooms.id
     * @return list<int>
     */
    private function writeOffers(Reservation $reservation, array $roomIds, OfferResult $offers, ?OfferContext $context, array $carried = []): array
    {
        $terms = collect($offers->applied)->keyBy('offer_id');
        $rows = [];
        foreach ($roomIds as $key => $roomId) {
            $perOffer = $carried[$roomId] ?? [];
            foreach ($offers->roomOffers($key) as $offerId => $o) {
                $perOffer[$offerId] = array_merge($perOffer[$offerId] ?? [], $o['nightly']);
            }
            foreach ($perOffer as $offerId => $nightly) {
                if ($nightly === []) {
                    continue;
                }
                ksort($nightly);
                $snapshot = ($terms[$offerId] ?? $carried['_terms'][$offerId] ?? ['offer_id' => $offerId]);
                unset($snapshot['amount']);
                $snapshot['nightly'] = $nightly;
                $snapshot['entered_code'] = $context?->promo() !== null && strtoupper((string) ($snapshot['promo_code'] ?? '')) === $context->promo() ? $context->promo() : null;
                $rows[] = [
                    'property_id' => $reservation->property_id, 'offer_id' => $offerId, 'reservation_id' => $reservation->id,
                    'reservation_room_id' => $roomId, 'discount_amount' => Money::round(Money::sum(array_values($nightly))),
                    'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
                ];
            }
        }
        if ($rows !== []) {
            DB::table('offer_applications')->insert($rows);
        }

        return array_values(array_unique(array_column($rows, 'offer_id')));
    }

    /** Modify: rewrites the applications; kept nights keep their frozen discounts; redemptions follow. */
    private function replaceOffers(Reservation $reservation, array $rooms, array $roomIds, array $removedIds, OfferResult $offers, ?OfferContext $context): void
    {
        // Rows of rooms that are not edited (finished earlier) stay as they are.
        $touched = array_values(array_unique(array_merge(array_filter(array_values($roomIds)), $removedIds)));
        $before = DB::table('offer_applications')->where('reservation_id', $reservation->id)->distinct()->pluck('offer_id')->map(fn ($id) => (int) $id)->all();
        $old = DB::table('offer_applications')->where('reservation_id', $reservation->id)->whereIn('reservation_room_id', $touched)->get();
        $carried = ['_terms' => []];
        foreach ($rooms as $key => $spec) {
            $keep = ! empty($spec['reoffer']) ? [] : ($spec['keep'] ?? []);
            $roomId = $roomIds[$key] ?? null;
            if ($keep === [] || $roomId === null) {
                continue;
            }
            foreach ($old->where('reservation_room_id', $roomId) as $row) {
                $snap = json_decode((string) $row->snapshot, true) ?: [];
                $nightly = array_intersect_key($snap['nightly'] ?? [], $keep);
                if ($nightly !== []) {
                    $carried[$roomId][(int) $row->offer_id] = $nightly;
                    $carried['_terms'][(int) $row->offer_id] = array_diff_key($snap, ['nightly' => 1, 'entered_code' => 1]);
                }
            }
        }
        DB::table('offer_applications')->where('reservation_id', $reservation->id)->whereIn('reservation_room_id', $touched)->delete();
        $this->writeOffers($reservation, $roomIds, $offers, $context, $carried);
        $after = DB::table('offer_applications')->where('reservation_id', $reservation->id)->distinct()->pluck('offer_id')->map(fn ($id) => (int) $id)->all();
        $this->offerService->redeem(array_values(array_diff($after, $before)));
        $this->offerService->release(array_values(array_diff($before, $after)));
    }

    public function today(Property $property): CarbonImmutable
    {
        return CarbonImmutable::parse($this->guard->today($property));
    }

    // ---------------------------------------------------------------- internals

    private function byIdempotencyKey(Property $property, string $key): ?Reservation
    {
        return Reservation::acrossProperties()->where('property_id', $property->id)->where('idempotency_key', $key)->first();
    }

    private function propertyOf(Reservation $reservation): Property
    {
        return Property::query()->withTrashed()->findOrFail($reservation->property_id);
    }

    private function defaultSourceId(): int
    {
        return (int) DB::table('booking_sources')->whereNull('property_id')->where('code', 'direct')->value('id');
    }

    /** @return array<int|string, array<string, mixed>> */
    private function normalizeRooms(Property $property, array $rooms): array
    {
        if ($rooms === []) {
            throw ValidationException::withMessages(['rooms' => __('reservations.errors.rooms_required')]);
        }
        if (count($rooms) > self::MAX_ROOMS) {
            throw ValidationException::withMessages(['rooms' => __('reservations.errors.too_many_rooms', ['max' => self::MAX_ROOMS])]);
        }
        $ages = null;
        $out = [];
        foreach ($rooms as $i => $r) {
            /** @var Product $product */
            $product = $r['product'];
            if ((int) $product->property_id !== (int) $property->id) {
                throw ValidationException::withMessages(["rooms.$i.rate_plan_id" => __('reservations.errors.rate_not_found')]);
            }
            $in = CarbonImmutable::parse($r['check_in'])->startOfDay();
            $outDate = CarbonImmutable::parse($r['check_out'])->startOfDay();
            if ($outDate->lessThanOrEqualTo($in)) {
                throw ValidationException::withMessages(["rooms.$i.check_out" => __('reservations.errors.date_order')]);
            }
            if ($in->diffInDays($outDate) > AvailabilityService::MAX_NIGHTS) {
                throw ValidationException::withMessages(["rooms.$i.check_out" => __('reservations.errors.too_long', ['max' => AvailabilityService::MAX_NIGHTS])]);
            }
            $children = (int) ($r['children'] ?? 0);
            $infants = (int) ($r['infants'] ?? 0);
            $childAges = $r['child_ages'] ?? null;
            if ($childAges === null || count($childAges) !== $children + $infants) {
                $ages ??= $this->defaultAges($property);
                $childAges = [...array_fill(0, $children, $ages['child']), ...array_fill(0, $infants, 0)];
            }
            $unit = $r['unit'] ?? null;
            if ($unit !== null && (int) $unit->room_type_id !== (int) $product->room_type_id) {
                throw ValidationException::withMessages(["rooms.$i.unit_id" => __('reservations.errors.unit_wrong_type')]);
            }
            $out[$i] = [
                'id' => isset($r['id']) ? (int) $r['id'] : null, 'product' => $product, 'check_in' => $in, 'check_out' => $outDate,
                'adults' => (int) ($r['adults'] ?? 1), 'children' => $children, 'infants' => $infants, 'child_ages' => array_map('intval', $childAges),
                'rate' => isset($r['rate']) && $r['rate'] !== '' && $r['rate'] !== null ? (string) $r['rate'] : null,
                'unit' => $unit, 'keep' => [], 'old' => null,
            ];
        }

        return $out;
    }

    /** @return array{child: int} */
    private function defaultAges(Property $property): array
    {
        $band = DB::table('property_age_bands')->where('property_id', $property->id)->where('code', 'child')->value('min_age');

        return ['child' => $band !== null ? max(2, (int) $band) : 8];
    }

    /**
     * Restrictions, occupancy and channel rules of the PMS for the given rooms (inventory is
     * checked by reserve() in the transaction).
     *
     * @param  list<int|string>  $keys  rooms to check
     */
    private function assertSellable(Property $property, array $rooms, array $keys, string $channel = 'pms'): void
    {
        $searches = [];
        foreach ($keys as $key) {
            $r = $rooms[$key];
            $sig = implode('|', [$r['check_in']->toDateString(), $r['check_out']->toDateString(), $r['adults'], $r['children'], $r['infants']]);
            $searches[$sig] ??= $this->availability->search($property, $r['check_in'], $r['check_out'], $r['adults'], $r['children'], $r['infants'], $channel, $r['child_ages']);
            $found = null;
            foreach ($searches[$sig]->roomTypes as $rt) {
                foreach ($rt['products'] as $p) {
                    if ((int) $p['product_id'] === (int) $r['product']->id) {
                        $found = $p;
                    }
                }
            }
            if ($found === null) {
                throw ValidationException::withMessages(["rooms.$key.rate_plan_id" => __('reservations.errors.rate_not_sellable')]);
            }
            $reasons = array_values(array_intersect($found['reasons'], self::BLOCKING_REASONS));
            if ($reasons !== []) {
                $text = implode(' · ', array_map(fn ($reason) => __('inventory.reasons.'.$reason), $reasons));
                throw ValidationException::withMessages(["rooms.$key.rate_plan_id" => __('reservations.errors.restricted', ['reasons' => $text])]);
            }
        }
    }

    /** @return array<int, array<string, int>> room type => [date => rooms] of the new stay */
    private function inventoryMap(array $rooms): array
    {
        $map = [];
        foreach ($rooms as $r) {
            $rt = (int) $r['product']->room_type_id;
            foreach (StayDates::nights($r['check_in'], $r['check_out']) as $date) {
                $map[$rt][$date] = ($map[$rt][$date] ?? 0) + 1;
            }
        }

        return $map;
    }

    private function reserveInventory(array $before, array $after, array $rooms): void
    {
        try {
            $this->delta->apply($before, $after);
        } catch (NotAvailableException $e) {
            $key = 'rooms';
            foreach ($rooms as $i => $r) {
                if ((int) $r['product']->room_type_id === $e->roomTypeId) {
                    $key = "rooms.$i.room_type_id";
                    break;
                }
            }
            $name = RoomType::query()->withTrashed()->whereKey($e->roomTypeId)->value('name');
            throw ValidationException::withMessages([$key => __('reservations.errors.not_available', [
                'room_type' => $name, 'dates' => implode(', ', array_slice($e->dates, 0, 5)),
            ])]);
        }
    }

    private function fillDetails(Reservation $reservation, array $data): void
    {
        foreach (self::DETAIL_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $reservation->{$field} = $data[$field] === '' ? null : $data[$field];
            }
        }
    }

    private function applyGuest(Reservation $reservation, Guest $guest): void
    {
        $reservation->primary_guest_id = $guest->id;
        $reservation->guest_name = mb_substr($guest->fullName(), 0, 170);
        $reservation->guest_phone = $guest->phone_e164;
    }

    private function applyTotals(Reservation $reservation, array $rooms, array $priced): void
    {
        $in = null;
        $out = null;
        $sum = ['room' => '0', 'tax' => '0', 'grand' => '0'];
        $people = ['adults' => 0, 'children' => 0, 'infants' => 0];
        foreach ($rooms as $key => $r) {
            $in = $in === null ? $r['check_in'] : $in->min($r['check_in']);
            $out = $out === null ? $r['check_out'] : $out->max($r['check_out']);
            $sum['room'] = Money::add($sum['room'], $priced[$key]['room_total']);
            $sum['tax'] = Money::add($sum['tax'], $priced[$key]['tax_total']);
            $sum['grand'] = Money::add($sum['grand'], $priced[$key]['grand_total']);
            foreach ($people as $k => $v) {
                $people[$k] = $v + $r[$k];
            }
        }
        $reservation->check_in = $in;
        $reservation->check_out = $out;
        $reservation->nights = (int) $in->diffInDays($out);
        $reservation->room_count = count($rooms);
        $reservation->fill($people);
        $reservation->room_total = Money::round($sum['room']);
        $reservation->tax_total = Money::round($sum['tax']);
        $reservation->grand_total = Money::round(Money::add($sum['grand'], (string) ($reservation->extras_total ?? '0'), Money::negate((string) ($reservation->discount_total ?? '0'))));
    }

    /** Totals, dates and guest counts from the rooms in the database (after a change). */
    private function recalculate(Reservation $reservation): void
    {
        $rooms = ReservationRoom::query()->where('reservation_id', $reservation->id)->get();
        $live = $rooms->whereNotIn('status', ['cancelled', 'no_show']);
        $basis = $live->isNotEmpty() ? $live : $rooms;
        $reservation->check_in = $basis->min(fn ($r) => $r->check_in);
        $reservation->check_out = $basis->max(fn ($r) => $r->check_out);
        $reservation->nights = (int) $reservation->check_in->diffInDays($reservation->check_out);
        $reservation->room_count = max(1, $live->count());
        $reservation->adults = (int) $live->sum('adults');
        $reservation->children = (int) $live->sum('children');
        $reservation->infants = (int) $live->sum('infants');
        $reservation->room_total = Money::round(Money::sum($live->map(fn ($r) => (string) $r->room_total)));
        $reservation->tax_total = Money::round(Money::sum($live->map(fn ($r) => (string) $r->tax_total)));
        $grand = Money::sum($live->map(fn ($r) => (string) $r->grand_total));
        $reservation->grand_total = Money::round(Money::add($grand, (string) ($reservation->extras_total ?? '0'), Money::negate((string) ($reservation->discount_total ?? '0'))));
    }

    private function newRoom(Reservation $reservation, array $spec, array $price, int $sort, string $status): ReservationRoom
    {
        /** @var Product $product */
        $product = $spec['product'];

        return ReservationRoom::query()->create([
            'property_id' => $reservation->property_id, 'reservation_id' => $reservation->id,
            'room_type_id' => $product->room_type_id, 'rate_plan_id' => $product->rate_plan_id, 'product_id' => $product->id,
            'status' => $status, 'check_in' => $spec['check_in'], 'check_out' => $spec['check_out'],
            'adults' => $spec['adults'], 'children' => $spec['children'], 'infants' => $spec['infants'],
            'child_ages' => $spec['child_ages'] ?: null, 'rate_snapshot' => $this->snapshot($product, $reservation->currency_code, $spec['rate']),
            'room_total' => $price['room_total'], 'discount_total' => $price['discount_total'] ?? '0.00', 'tax_total' => $price['tax_total'], 'grand_total' => $price['grand_total'],
            'sort_order' => $sort,
        ]);
    }

    private function updateRoom(ReservationRoom $room, array $spec, array $price, int $sort, bool $holds, string $key, array &$changes): void
    {
        $product = $spec['product'];
        $oldUnit = DB::table('unit_nights')->where('reservation_room_id', $room->id)->orderByDesc('stay_date')->value('unit_id');
        $productChanged = (int) $room->product_id !== (int) $product->id;
        $datesChanged = ! $room->check_in->equalTo($spec['check_in']) || ! $room->check_out->equalTo($spec['check_out']);

        $room->fill([
            'room_type_id' => $product->room_type_id, 'rate_plan_id' => $product->rate_plan_id, 'product_id' => $product->id,
            'check_in' => $spec['check_in'], 'check_out' => $spec['check_out'],
            'adults' => $spec['adults'], 'children' => $spec['children'], 'infants' => $spec['infants'],
            'child_ages' => $spec['child_ages'] ?: null,
            'room_total' => $price['room_total'], 'discount_total' => $price['discount_total'] ?? '0.00', 'tax_total' => $price['tax_total'], 'grand_total' => $price['grand_total'], 'sort_order' => $sort,
        ]);
        if ($productChanged || $spec['rate'] !== null) {
            $room->rate_snapshot = $this->snapshot($product, (string) ($room->rate_snapshot['currency'] ?? ''), $spec['rate']);
        }
        $room->save();
        $this->writeNights($room, $price['nights'], $holds);

        // PMS room: an explicit choice wins; otherwise keep the room for the new dates when the type is unchanged.
        $unit = $spec['unit'];
        $keepUnit = $unit === null && $oldUnit !== null && (int) DB::table('physical_units')->where('id', $oldUnit)->value('room_type_id') === (int) $product->room_type_id
            ? PhysicalUnit::query()->find($oldUnit) : null;
        $target = $unit ?? $keepUnit;
        if ($target === null || $datesChanged || $productChanged || (int) $target->id !== (int) $oldUnit) {
            $from = $room->status === 'checked_in' ? $this->today($this->propertyOf($room->reservation))->max($room->check_in) : $room->check_in;
            DB::table('unit_nights')->where('reservation_room_id', $room->id)
                ->where(fn ($q) => $q->where('stay_date', '>=', $from->toDateString())->orWhere('stay_date', '<', $room->check_in->toDateString()))->delete();
            if ($target !== null && $from->lessThan($room->check_out)) {
                $this->writeUnitNights($room, $target, $from, $room->check_out, "rooms.$key.unit_id");
            }
            if ($oldUnit !== null && $target !== null && (int) $target->id !== (int) $oldUnit) {
                $changes['rooms']['moved'][] = [$room->id, (int) $oldUnit, $target->id];
            }
        }
    }

    /**
     * Upserts the nights of the stay (active when the reservation holds inventory) and
     * deactivates nights that are no longer part of it. Rows are kept for history.
     *
     * @param  array<string, array<string, string>>  $nights
     */
    private function writeNights(ReservationRoom $room, array $nights, bool $active): void
    {
        $rows = [];
        foreach ($nights as $date => $n) {
            $rows[] = [
                'reservation_room_id' => $room->id, 'stay_date' => $date, 'property_id' => $room->property_id,
                'room_type_id' => $room->room_type_id, 'product_id' => $room->product_id,
                'base_price' => $n['base_price'], 'occupancy_adjust' => $n['occupancy_adjust'], 'discount' => $n['discount'],
                'net_price' => $n['net_price'], 'tax_amount' => $n['tax_amount'], 'is_active' => $active ? 1 : 0,
            ];
        }
        DB::table('reservation_room_nights')->upsert($rows, ['reservation_room_id', 'stay_date'],
            ['room_type_id', 'product_id', 'base_price', 'occupancy_adjust', 'discount', 'net_price', 'tax_amount', 'is_active']);
        DB::table('reservation_room_nights')->where('reservation_room_id', $room->id)
            ->whereNotIn('stay_date', array_keys($nights))->where('is_active', 1)->update(['is_active' => 0]);
        $room->unsetRelation('nights');
    }

    /** Puts the guest in $unit for [from, to); the unit_nights primary key forbids double assignment. */
    private function writeUnitNights(ReservationRoom $room, PhysicalUnit $unit, CarbonImmutable $from, CarbonImmutable $to, string $field): void
    {
        if (! $unit->is_active || $unit->trashed()) {
            throw ValidationException::withMessages([$field => __('reservations.errors.unit_inactive', ['room' => $unit->name])]);
        }
        $taken = DB::table('unit_nights')->where('unit_id', $unit->id)
            ->where('stay_date', '>=', $from->toDateString())->where('stay_date', '<', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('reservation_room_id')->orWhere('reservation_room_id', '!=', $room->id))
            ->orderBy('stay_date')->value('stay_date');
        $blocked = DB::table('unit_blocks')->where('unit_id', $unit->id)->whereNull('released_at')
            ->whereIn('block_type', InventoryService::BLOCKING_TYPES)
            ->where('start_date', '<', $to->toDateString())->where('end_date', '>', $from->toDateString())->exists();
        if ($taken !== null || $blocked) {
            throw ValidationException::withMessages([$field => __('reservations.errors.unit_taken', ['room' => $unit->name, 'date' => $taken ?? $from->toDateString()])]);
        }
        $now = now();
        $rows = array_map(fn ($d) => [
            'unit_id' => $unit->id, 'stay_date' => $d, 'property_id' => $room->property_id, 'kind' => 'reservation',
            'reservation_room_id' => $room->id, 'unit_block_id' => null, 'created_at' => $now,
        ], StayDates::nights($from, $to));
        try {
            DB::table('unit_nights')->insert($rows);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages([$field => __('reservations.errors.unit_taken', ['room' => $unit->name, 'date' => $from->toDateString()])]);
            }
            throw $e;
        }
    }

    /** Ends every open room with $status, releasing inventory and PMS rooms; used by cancel / no-show. */
    private function finish(Reservation $reservation, string $status, ?User $by, ?string $note, callable $extra): Reservation
    {
        return Tx::run(function () use ($reservation, $status, $by, $note, $extra) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== $reservation->status) {
                throw ValidationException::withMessages(['reservation' => __('reservations.errors.changed_meanwhile')]);
            }
            $from = $reservation->status;
            foreach ($reservation->rooms->whereNotIn('status', ['cancelled', 'no_show', 'checked_out']) as $room) {
                $this->closeRoom($room, $status, $by);
            }
            $reservation->status = $status;
            $extra($reservation);
            $reservation->updated_by = $by?->id;
            $reservation->save();
            $this->history($reservation, $from, $status, $by, $note);
            $this->audit->log('reservation.'.$status, $reservation, [
                'before' => ['status' => $from], 'after' => ['status' => $status, 'fee' => (string) $reservation->cancellation_fee],
            ], $reservation->property_id, $by?->id);

            return $reservation;
        });
    }

    private function closeRoom(ReservationRoom $room, string $status, ?User $by): void
    {
        $active = DB::table('reservation_room_nights')->where('reservation_room_id', $room->id)->where('is_active', 1)->pluck('stay_date')->all();
        if ($active !== []) {
            $this->delta->apply([$room->room_type_id => array_fill_keys(array_map(fn ($d) => substr((string) $d, 0, 10), $active), 1)], []);
            DB::table('reservation_room_nights')->where('reservation_room_id', $room->id)->update(['is_active' => 0]);
        }
        DB::table('unit_nights')->where('reservation_room_id', $room->id)->delete();
        $from = $room->status;
        $room->status = $status;
        $room->save();
        $this->history($room->reservation ?? Reservation::query()->find($room->reservation_id), $from, $status, $by, null, $room->id);
    }

    /** Room totals from its active nights (after an early departure). */
    private function sumRoom(ReservationRoom $room): void
    {
        $sums = DB::table('reservation_room_nights')->where('reservation_room_id', $room->id)->where('is_active', 1)
            ->selectRaw('COALESCE(SUM(net_price), 0) AS net, COALESCE(SUM(tax_amount), 0) AS tax')->first();
        $room->room_total = Money::round((string) $sums->net);
        $room->tax_total = Money::round((string) $sums->tax);
        $room->grand_total = Money::round(Money::add((string) $sums->net, (string) $sums->tax));
    }

    private function linkGuests(Reservation $reservation, Guest $primary, ?array $companions, Property $property, ?User $by): void
    {
        $ids = [$primary->id => ['is_primary' => 1, 'reservation_room_id' => null]];
        if ($companions === null) {
            // Keep existing companions, only the primary changes.
            $current = DB::table('reservation_guests')->where('reservation_id', $reservation->id)->where('is_primary', 0)->pluck('reservation_room_id', 'guest_id');
            foreach ($current as $guestId => $roomId) {
                $ids[$guestId] ??= ['is_primary' => 0, 'reservation_room_id' => $roomId];
            }
        } else {
            foreach ($companions as $c) {
                if (empty($c['first_name'])) {
                    continue;
                }
                $g = $this->guests->resolve($property, $c, null, false, $by);
                $ids[$g->id] ??= ['is_primary' => 0, 'reservation_room_id' => null];
            }
        }
        DB::table('reservation_guests')->where('reservation_id', $reservation->id)->delete();
        DB::table('reservation_guests')->insert(array_map(fn ($guestId, $pivot) => ['reservation_id' => $reservation->id, 'guest_id' => $guestId] + $pivot, array_keys($ids), $ids));
    }

    private function history(Reservation $reservation, ?string $from, string $to, ?User $by, ?string $note, ?int $roomId = null): void
    {
        DB::table('reservation_status_history')->insert([
            'reservation_id' => $reservation->id, 'reservation_room_id' => $roomId, 'from_status' => $from, 'to_status' => $to,
            'user_id' => $by?->id, 'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'created_at' => now(),
        ]);
    }

    /** Frozen rate plan, meal plan and cancellation rules (the policy that applies to this booking). */
    private function snapshot(Product $product, string $currency, ?string $manualRate): array
    {
        $plan = $product->relationLoaded('ratePlan') ? $product->ratePlan : $product->ratePlan()->first();
        $plan?->loadMissing(['mealPlan', 'cancellationPolicy']);
        $policy = $plan?->cancellationPolicy;
        $rules = $policy ? DB::table('cancellation_policy_rules')->where('policy_id', $policy->id)
            ->orderBy('hours_before_arrival')->get(['applies_to', 'hours_before_arrival', 'charge_type', 'charge_value'])
            ->map(fn ($r) => (array) $r)->all() : [];

        return [
            'currency' => $currency,
            'rate_plan' => ['code' => $plan?->code, 'name' => $plan?->name, 'payment_type' => $plan?->payment_type, 'deposit_value' => $plan?->deposit_value],
            'meal_plan' => ['code' => $plan?->mealPlan?->code, 'name' => $plan?->mealPlan?->name],
            'cancellation' => ['code' => $policy?->code, 'name' => $policy?->name, 'refundable' => (bool) ($policy?->is_refundable ?? true), 'rules' => $rules],
            'manual_rate' => $manualRate,
        ];
    }
}
