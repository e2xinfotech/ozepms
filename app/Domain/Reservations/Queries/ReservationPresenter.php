<?php

namespace App\Domain\Reservations\Queries;

use App\Domain\Access\AccessService;
use App\Domain\Accommodation\UnitNightGuard;
use App\Domain\Reservations\CancellationFee;
use App\Domain\Reservations\ReservationService;
use App\Models\GuestDocument;
use App\Models\Note;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * JSON shapes of reservations for the list, the side panel, the detail page and the front desk.
 * Public ids only; money as decimal strings; dates as Y-m-d (stay) or ISO 8601 (timestamps).
 */
class ReservationPresenter
{
    public function __construct(
        private readonly UnitNightGuard $guard,
        private readonly CancellationFee $fees,
        private readonly AccessService $access,
    ) {}

    /**
     * PMS rooms per reservation room in one query: room id => [['id', 'name', 'from', 'housekeeping']] by first night.
     *
     * @param  list<int>  $roomIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function unitsFor(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }
        $rows = DB::table('unit_nights')
            ->join('physical_units', 'physical_units.id', '=', 'unit_nights.unit_id')
            ->whereIn('unit_nights.reservation_room_id', $roomIds)
            ->orderBy('unit_nights.reservation_room_id')->orderBy('unit_nights.stay_date')
            ->get(['unit_nights.reservation_room_id', 'unit_nights.stay_date', 'physical_units.public_id', 'physical_units.name', 'physical_units.housekeeping_status']);
        // One segment per run of consecutive nights in the same PMS room (a split stay has several).
        $out = [];
        foreach ($rows as $r) {
            $rid = (int) $r->reservation_room_id;
            $date = substr((string) $r->stay_date, 0, 10);
            $last = isset($out[$rid]) ? array_key_last($out[$rid]) : null;
            if ($last !== null && $out[$rid][$last]['id'] === $r->public_id && date('Y-m-d', strtotime($out[$rid][$last]['to'].' +1 day')) === $date) {
                $out[$rid][$last]['to'] = $date;

                continue;
            }
            $out[$rid][] = ['id' => $r->public_id, 'name' => $r->name, 'housekeeping' => $r->housekeeping_status, 'from' => $date, 'to' => $date];
        }

        return $out;
    }

    /** One list / front desk row. Needs rooms.roomType, rooms.ratePlan, source, primaryGuest loaded. */
    public function row(Reservation $r, array $units, string $today): array
    {
        $guest = $r->primaryGuest;
        $roomNames = [];
        foreach ($r->rooms as $room) {
            if (in_array($room->status, ['cancelled', 'no_show'], true) && $r->rooms->count() > 1) {
                continue;
            }
            $current = $this->currentUnit($units[$room->id] ?? [], $today);
            if ($current !== null) {
                $roomNames[] = $current['name'];
            }
        }
        $live = $r->rooms->whereNotIn('status', ['cancelled', 'no_show']);
        $first = ($live->first() ?? $r->rooms->first());
        $types = ($live->isNotEmpty() ? $live : $r->rooms)->map(fn ($x) => $x->roomType)->filter()->unique('id');

        return [
            'id' => $r->public_id,
            'ref' => $r->booking_ref,
            'status' => $r->status,
            'payment_status' => $r->payment_status,
            'guest' => [
                'id' => $guest?->public_id,
                'name' => $r->guest_name,
                'email' => $guest?->email,
                'phone' => $guest?->phone_e164 ?? $r->guest_phone,
                'nationality' => $guest?->nationality_iso2,
                'vip' => (bool) ($guest?->is_vip ?? false),
            ],
            'units' => $roomNames,
            'room_type' => $types->map(fn ($t) => ['name' => $t->name, 'code' => $t->code])->values()->all(),
            'rate_plan' => $first?->ratePlan ? ['name' => $first->ratePlan->name, 'code' => $first->ratePlan->code] : null,
            'rooms' => $r->rooms->map(fn (ReservationRoom $room) => [
                'id' => $room->public_id, 'status' => $room->status,
                'room_type' => $room->roomType?->name, 'room_type_id' => $room->roomType?->public_id,
                'check_in' => $room->check_in->toDateString(), 'check_out' => $room->check_out->toDateString(),
                'unit' => $this->currentUnit($units[$room->id] ?? [], $today),
            ])->values()->all(),
            'check_in' => $r->check_in->toDateString(),
            'check_out' => $r->check_out->toDateString(),
            'arrival_time' => $r->arrival_time ? substr((string) $r->arrival_time, 0, 5) : null,
            'departure_time' => $r->departure_time ? substr((string) $r->departure_time, 0, 5) : null,
            'nights' => (int) $r->nights,
            'room_count' => (int) $r->room_count,
            'adults' => (int) $r->adults,
            'children' => (int) $r->children,
            'infants' => (int) $r->infants,
            'total' => (string) $r->grand_total,
            'balance' => (string) $r->balance_due,
            'currency' => $r->currency_code,
            'source' => $r->source ? ['code' => $r->source->code, 'name' => $this->sourceName($r->source->code, $r->source->name)] : null,
            'created_at' => $r->created_at?->toIso8601String(),
            // Arrival day passed and the guest has not arrived / departure day passed and still in-house.
            'late_arrival' => in_array($r->status, ['pending', 'confirmed'], true) && $r->check_in->toDateString() < $today,
            'late_departure' => $r->status === 'checked_in' && $r->check_out->toDateString() < $today,
        ];
    }

    /** Full reservation for the detail page and the side panel. */
    public function detail(Reservation $r, Property $property, ?User $user): array
    {
        $r->loadMissing([
            'rooms.roomType:id,public_id,code,name', 'rooms.roomType.images' => fn ($q) => $q->orderBy('sort_order')->limit(1),
            'rooms.ratePlan:id,public_id,code,name', 'rooms.product:id,public_id', 'rooms.nights',
            'source:id,code,name', 'primaryGuest', 'creator:id,name', 'updater:id,name',
        ]);
        $today = $this->guard->today($property);
        $units = $this->unitsFor($r->rooms->pluck('id')->all());
        $row = $this->row($r, $units, $today);
        $guest = $r->primaryGuest;
        $canSeeId = $user !== null && $this->access->allows($user, 'guests.update');

        $components = [];
        $rooms = $r->rooms->map(function (ReservationRoom $room) use ($units, $today, &$components) {
            $snap = $room->rate_snapshot ?? [];
            $nights = $room->nights->map(fn ($n) => [
                'date' => $n->stay_date->toDateString(), 'price' => Money::round(Money::add((string) $n->base_price, (string) $n->occupancy_adjust)),
                'net' => (string) $n->net_price, 'tax' => (string) $n->tax_amount, 'active' => (bool) $n->is_active,
            ])->values()->all();
            $active = array_values(array_filter($nights, fn ($n) => $n['active']));
            $image = $room->roomType?->images->first();

            return [
                'id' => $room->public_id,
                'status' => $room->status,
                'room_type' => $room->roomType ? ['id' => $room->roomType->public_id, 'code' => $room->roomType->code, 'name' => $room->roomType->name,
                    'image' => $image?->url()] : null,
                'rate_plan' => ['id' => $room->ratePlan?->public_id, 'code' => $snap['rate_plan']['code'] ?? $room->ratePlan?->code, 'name' => $snap['rate_plan']['name'] ?? $room->ratePlan?->name],
                'product_id' => $room->product?->public_id,
                'meal_plan' => $snap['meal_plan']['name'] ?? null,
                'policy' => ['name' => $snap['cancellation']['name'] ?? null, 'refundable' => (bool) ($snap['cancellation']['refundable'] ?? true), 'rules' => $snap['cancellation']['rules'] ?? []],
                'manual_rate' => $snap['manual_rate'] ?? null,
                'check_in' => $room->check_in->toDateString(),
                'check_out' => $room->check_out->toDateString(),
                'nights' => $room->nightCount(),
                'adults' => (int) $room->adults, 'children' => (int) $room->children, 'infants' => (int) $room->infants,
                'child_ages' => $room->child_ages ?? [],
                'unit' => $this->currentUnit($units[$room->id] ?? [], $today),
                'units' => $units[$room->id] ?? [],
                'room_total' => (string) $room->room_total, 'tax_total' => (string) $room->tax_total, 'grand_total' => (string) $room->grand_total,
                'rate' => $active !== [] ? $active[0]['price'] : null,
                'nightly' => $active,
                'checked_in_at' => $room->checked_in_at?->toIso8601String(),
                'checked_out_at' => $room->checked_out_at?->toIso8601String(),
            ];
        })->values()->all();

        // Tax components (CGST / SGST …) from the frozen night taxes are not stored per component;
        // the price breakdown shows the total and the effective rate.
        $taxRate = Money::isPositive((string) $r->room_total) ? Money::round(Money::mul(Money::div((string) $r->tax_total, (string) $r->room_total), '100'), 2) : '0.00';

        $billing = $this->billing($r, $today);
        $state = $this->actions($r, $today, $user);
        // Offers frozen on the booking (offer_applications) — shown as discount lines.
        $offerRows = DB::table('offer_applications')->where('reservation_id', $r->id)->get(['reservation_room_id', 'discount_amount', 'snapshot']);
        $liveRooms = $r->rooms->whereNotIn('status', ['cancelled', 'no_show'])->pluck('id')->all();
        $offers = [];
        $promoCode = null;
        foreach ($offerRows as $app) {
            if (! in_array((int) $app->reservation_room_id, $liveRooms, true)) {
                continue;
            }
            $snap = json_decode((string) $app->snapshot, true) ?: [];
            $k = (string) ($snap['id'] ?? $snap['offer_id'] ?? '');
            $offers[$k] ??= ['id' => $snap['id'] ?? null, 'name' => $snap['name'] ?? '—', 'promo_code' => $snap['promo_code'] ?? null, 'amount' => '0'];
            $offers[$k]['amount'] = Money::round(Money::add($offers[$k]['amount'], (string) $app->discount_amount));
            $promoCode ??= $snap['entered_code'] ?? null;
        }
        $offerDiscount = Money::round(Money::sum(array_column($offers, 'amount')));

        return array_merge($row, [
            'rooms' => $rooms,
            'source_id' => $r->source_id,
            'arrival_time' => $r->arrival_time ? substr((string) $r->arrival_time, 0, 5) : null,
            'departure_time' => $r->departure_time ? substr((string) $r->departure_time, 0, 5) : null,
            'purpose' => $r->purpose, 'market' => $r->market, 'travel_agent' => $r->travel_agent, 'company_name' => $r->company_name,
            'channel_ref' => $r->channel_ref, 'special_requests' => $r->special_requests, 'internal_notes' => $r->internal_notes,
            'offers' => array_values($offers),
            'promo_code' => $promoCode,
            'totals' => [
                // Room charges before offers; "discount" = offers + folio discounts (the subtotal is unchanged).
                'room_total' => Money::round(Money::add((string) $r->room_total, $offerDiscount)), 'extras_total' => (string) $r->extras_total,
                'discount_total' => Money::round(Money::add((string) $r->discount_total, $offerDiscount)), 'offer_discount' => $offerDiscount,
                'subtotal' => Money::round(Money::add((string) $r->room_total, (string) $r->extras_total, Money::negate((string) $r->discount_total))),
                'tax_total' => (string) $r->tax_total, 'tax_rate' => $taxRate, 'grand_total' => (string) $r->grand_total,
                'paid' => $billing['paid'], 'balance' => $billing['balance'], 'checkout_balance' => $billing['checkout_balance'], 'billing_ready' => $billing['ready'],
            ],
            'cancellation' => [
                'fee' => $r->cancellation_fee !== null ? (string) $r->cancellation_fee : null,
                'reason' => $r->cancel_reason, 'at' => $r->cancelled_at?->toIso8601String(),
                // What cancelling now would cost (shown in the confirm dialog).
                'fee_now' => in_array($r->status, ['pending', 'confirmed', 'hold'], true) ? $this->fees->forReservation($r, $property) : null,
            ],
            'extras_by_department' => Money::isZero((string) $r->extras_total) ? [] : $this->extrasByDepartment($r->id),
            'can_see_ids' => $canSeeId,
            'guest_profile' => $guest ? [
                'id' => $guest->public_id, 'number' => $guest->number(), 'title' => $guest->title, 'guest_type' => $guest->guest_type,
                'first_name' => $guest->first_name, 'last_name' => $guest->last_name, 'email' => $guest->email, 'phone' => $guest->phone_e164,
                'nationality' => $guest->nationality_iso2, 'gender' => $guest->gender, 'country' => $guest->country_iso2, 'date_of_birth' => $guest->date_of_birth?->toDateString(),
                'address' => array_values(array_filter([$guest->address_line1, $guest->address_line2, $guest->city, $guest->postcode])),
                'company_name' => $guest->company_name, 'company_tax_no' => $guest->company_tax_no,
                'id_type' => $guest->id_type, 'id_number' => $canSeeId ? $guest->id_number_enc : $guest->maskedIdNumber(),
                'id_issuing' => $guest->id_issuing_iso2, 'id_expiry' => $guest->id_expiry?->toDateString(),
                'vip' => (bool) $guest->is_vip, 'tags' => $guest->tags ?? [], 'notes' => $guest->notes, 'preferences' => $guest->preferences,
            ] : null,
            'companions' => DB::table('reservation_guests')->join('guests', 'guests.id', '=', 'reservation_guests.guest_id')
                ->where('reservation_guests.reservation_id', $r->id)->where('reservation_guests.is_primary', 0)
                ->get(['guests.public_id', 'guests.first_name', 'guests.last_name', 'guests.nationality_iso2', 'reservation_guests.person_type', 'reservation_guests.age'])
                ->map(fn ($g) => ['id' => $g->public_id, 'name' => trim($g->first_name.' '.$g->last_name), 'nationality' => $g->nationality_iso2, 'type' => $g->person_type, 'age' => $g->age])->all(),
            'created_by' => $r->creator?->name,
            'updated_by' => $r->updater?->name,
            'updated_at' => $r->updated_at?->toIso8601String(),
            'confirmed_at' => $r->confirmed_at?->toIso8601String(),
            'actions' => $state,
            'today' => $today,
        ]);
    }

    /** History tab: status changes, notes and audit entries, newest first (loaded on demand). */
    public function history(Reservation $r): array
    {
        $users = [];
        $status = DB::table('reservation_status_history')->leftJoin('users', 'users.id', '=', 'reservation_status_history.user_id')
            ->leftJoin('reservation_rooms', 'reservation_rooms.id', '=', 'reservation_status_history.reservation_room_id')
            ->where('reservation_status_history.reservation_id', $r->id)->orderByDesc('reservation_status_history.id')->limit(100)
            ->get(['reservation_status_history.*', 'users.name as user_name', 'reservation_rooms.sort_order']);
        $audit = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.property_id', $r->property_id)->where('audit_logs.entity_type', 'reservation')->where('audit_logs.entity_id', $r->id)
            ->whereNotIn('audit_logs.action', ['reservation.created', 'reservation.cancelled', 'reservation.no_show', 'reservation.confirmed', 'reservation.checked_in', 'reservation.checked_out', 'reservation.note_added'])
            ->orderByDesc('audit_logs.id')->limit(100)->get(['audit_logs.action', 'audit_logs.changes', 'audit_logs.created_at', 'users.name as user_name']);

        $events = [];
        foreach ($status as $s) {
            $events[] = ['type' => 'status', 'at' => $this->iso($s->created_at), 'user' => $s->user_name, 'from' => $s->from_status, 'to' => $s->to_status,
                'room' => $s->reservation_room_id ? ((int) $s->sort_order + 1) : null, 'note' => $s->note];
        }
        foreach ($audit as $a) {
            $changes = json_decode((string) $a->changes, true) ?: [];
            $events[] = ['type' => 'change', 'at' => $this->iso($a->created_at), 'user' => $a->user_name, 'action' => $a->action,
                'keys' => array_keys($changes['changes'] ?? $changes['after'] ?? []), 'after' => $changes['after'] ?? null];
        }
        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return [
            'events' => $events,
            'notes' => Note::query()->where('subject_type', 'reservation')->where('subject_id', $r->id)->with('user:id,name')
                ->orderByDesc('id')->limit(100)->get()->map(fn (Note $n) => ['id' => $n->id, 'body' => $n->body, 'user' => $n->user?->name, 'at' => $n->created_at?->toIso8601String()])->all(),
            'documents' => GuestDocument::query()->with('guest:id,public_id')->where(fn ($q) => $q->where('reservation_id', $r->id)->orWhere('guest_id', $r->primary_guest_id ?? 0))
                ->orderByDesc('id')->limit(50)->get()->map(fn (GuestDocument $d) => [
                    'id' => $d->public_id, 'type' => $d->doc_type, 'name' => $d->file_name, 'size' => (int) $d->size_bytes, 'mime' => $d->mime,
                    'at' => $d->created_at?->toIso8601String(), 'guest_id' => $d->guest?->public_id,
                ])->all(),
        ];
    }

    /** @return array<string, bool> which quick actions apply now (permission and state) */
    public function actions(Reservation $r, string $today, ?User $user): array
    {
        $can = fn (string $p) => $user !== null && $this->access->allows($user, $p);
        $open = in_array($r->status, Reservation::OPEN, true);
        $arrived = $r->check_in->toDateString() <= $today;

        return [
            'edit' => $open && $can('reservations.update'),
            'email' => $can('reservations.update'),
            'cancel' => in_array($r->status, ['inquiry', 'hold', 'pending', 'confirmed'], true) && $can('reservations.cancel'),
            'confirm' => in_array($r->status, ['inquiry', 'hold', 'pending'], true) && $can('reservations.update'),
            'no_show' => in_array($r->status, ['pending', 'confirmed'], true) && $arrived && $can('reservations.cancel'),
            'check_in' => in_array($r->status, ['pending', 'confirmed', 'checked_in'], true) && $arrived && $r->check_out->toDateString() > $today
                && $r->rooms->contains(fn ($x) => in_array($x->status, ['pending', 'confirmed'], true)) && $can('checkin.perform'),
            'check_out' => $r->status === 'checked_in' && $can('checkout.perform'),
            'override_balance' => $can('checkout.override_balance'),
            'assign' => $open && ($can('reservations.update') || $can('checkin.perform')),
            'note' => $can('reservations.update') || $can('reservations.view'),
            'billing' => $can('folio.view'),
            'payments' => $can('payments.manage'),
            'charges' => $can('folio.post'),
        ];
    }

    /** "paid / balance": billing's FolioService::summary() when installed, else the reservation columns. */
    public function billing(Reservation $r, ?string $today = null): array
    {
        $class = 'App\\Domain\\Billing\\FolioService';
        if (class_exists($class) && method_exists($class, 'summary')) {
            try {
                $s = app($class)->summary($r);
                $balance = (string) ($s['balance'] ?? $r->balance_due);
                // In house: what checking out today costs (nights after today are not charged).
                $atCheckout = $today !== null && $r->status === 'checked_in' && method_exists($class, 'checkoutBalance')
                    ? (string) app($class)->checkoutBalance($r, $today) : $balance;

                return ['paid' => (string) ($s['payments'] ?? $r->paid_total), 'balance' => $balance, 'checkout_balance' => $atCheckout, 'ready' => true];
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ['paid' => (string) $r->paid_total, 'balance' => (string) $r->balance_due, 'checkout_balance' => (string) $r->balance_due, 'ready' => false];
    }

    /**
     * Posted extras per outlet (restaurant, bar …) with their tax, for the price breakdown.
     *
     * @return list<array{department: string, amount: string, tax: string, total: string}>
     */
    private function extrasByDepartment(int $reservationId): array
    {
        return DB::table('folio_lines as fl')->join('folios as f', 'f.id', '=', 'fl.folio_id')
            ->where('f.reservation_id', $reservationId)->where('fl.line_type', 'service')->whereNotNull('fl.department')
            ->where('fl.is_void', 0)->whereNull('fl.void_of_line_id')
            ->groupBy('fl.department')->orderBy('fl.department')
            ->get(['fl.department', DB::raw('SUM(fl.amount) AS amount'), DB::raw('SUM(fl.tax_amount) AS tax')])
            ->map(fn ($r) => ['department' => $r->department, 'amount' => Money::round((string) $r->amount), 'tax' => Money::round((string) $r->tax), 'total' => Money::round(Money::add((string) $r->amount, (string) $r->tax))])->all();
    }

    /** Unit for tonight (in-house) or the first assigned night. */
    public function currentUnit(array $units, string $today): ?array
    {
        if ($units === []) {
            return null;
        }
        foreach ($units as $u) {
            if ($u['from'] <= $today && $u['to'] >= $today) {
                return $u;
            }
        }
        foreach ($units as $u) {
            if ($u['from'] > $today) {
                return $u;
            }
        }

        return end($units) ?: null;
    }

    public function sourceName(string $code, string $name): string
    {
        $key = 'reservations.sources.'.$code;
        $text = __($key);

        return $text === $key ? $name : $text;
    }

    private function iso(mixed $value): string
    {
        return \Carbon\CarbonImmutable::parse((string) $value, 'UTC')->toIso8601String();
    }
}
