<?php

namespace App\Domain\Reservations\Queries;

use App\Domain\Accommodation\UnitNightGuard;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Top bar search box: classifies the input (SearchTerm) and runs only the matching indexed
 * queries, at most LIMIT results per group: reservations, guests, PMS rooms.
 *   ref    uq_res_ref prefix            email  ix_guests_email (email_lc prefix)
 *   phone  ix_guests_phone_rev prefix   room   uq_units_name (exact)
 *   name   ix_res_guest_name prefix + ft_guests_name (ngram FULLTEXT)
 */
class GlobalSearch
{
    public const LIMIT = 8;

    public function __construct(private readonly UnitNightGuard $guard) {}

    /** @return array{reservations: list<array>, guests: list<array>, rooms: list<array>} */
    public function search(Property $property, string $input, bool $reservations = true, bool $guests = true): array
    {
        $out = ['reservations' => [], 'guests' => [], 'rooms' => []];
        $term = SearchTerm::parse($input);
        if ($term === null) {
            return $out;
        }
        $pid = $property->id;
        $guestIds = [];

        if ($term->kind === 'email' || $term->kind === 'phone' || $term->kind === 'name') {
            $q = DB::table('guests')->where('property_id', $pid)->whereNull('anonymized_at')->where('is_companion', 0);
            match ($term->kind) {
                'email' => $q->where('email_lc', 'like', SearchTerm::prefix($term->value)),
                'phone' => $q->where('phone_rev', 'like', SearchTerm::prefix(strrev($term->value))),
                default => $term->fulltext() !== ''
                    ? $q->whereRaw('MATCH (first_name, last_name, email) AGAINST (? IN BOOLEAN MODE)', [$term->fulltext()])
                    : $q->where('first_name', 'like', SearchTerm::prefix($term->value)),
            };
            $rows = $q->orderByDesc('id')->limit(self::LIMIT)->get(['id', 'public_id', 'guest_no', 'first_name', 'last_name', 'email', 'phone_e164', 'nationality_iso2']);
            $guestIds = $rows->pluck('id')->all();
            if ($guests) {
                $out['guests'] = $rows->map(fn ($g) => [
                    'id' => $g->public_id, 'number' => 'G-'.str_pad((string) $g->guest_no, 6, '0', STR_PAD_LEFT),
                    'name' => trim($g->first_name.' '.$g->last_name), 'email' => $g->email, 'phone' => $g->phone_e164, 'nationality' => $g->nationality_iso2,
                ])->all();
            }
        }

        if ($reservations) {
            $q = DB::table('reservations')->where('property_id', $pid);
            match ($term->kind) {
                'ref' => $q->where('booking_ref', 'like', SearchTerm::prefix($term->value)),
                'name' => $q->where(fn ($w) => $w->where('guest_name', 'like', SearchTerm::prefix($term->value))
                    ->when($guestIds !== [], fn ($x) => $x->orWhereIn('primary_guest_id', $guestIds))
                    ->orWhere('channel_ref', $term->value)),
                default => $q->whereIn('primary_guest_id', $guestIds ?: [0]),
            };
            $out['reservations'] = $this->reservations($q->orderByDesc('check_in')->limit(self::LIMIT));
        }

        if ($term->room !== null) {
            $today = $this->guard->today($property);
            $units = DB::table('physical_units')->where('physical_units.property_id', $pid)->whereNull('physical_units.deleted_at')
                ->where('physical_units.name', 'like', SearchTerm::prefix($term->room))
                ->join('room_types', 'room_types.id', '=', 'physical_units.room_type_id')
                ->leftJoin('unit_nights', fn ($j) => $j->on('unit_nights.unit_id', '=', 'physical_units.id')->where('unit_nights.stay_date', '=', $today))
                ->leftJoin('reservation_rooms', 'reservation_rooms.id', '=', 'unit_nights.reservation_room_id')
                ->leftJoin('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
                ->orderBy('physical_units.name')->limit(self::LIMIT)
                ->get(['physical_units.public_id', 'physical_units.name', 'physical_units.housekeeping_status', 'room_types.name as room_type',
                    'reservations.public_id as reservation_id', 'reservations.booking_ref', 'reservations.guest_name', 'reservations.status']);
            $out['rooms'] = $units->map(fn ($u) => [
                'id' => $u->public_id, 'name' => $u->name, 'room_type' => $u->room_type, 'housekeeping' => $u->housekeeping_status,
                'reservation' => $u->reservation_id ? ['id' => $u->reservation_id, 'ref' => $u->booking_ref, 'guest' => $u->guest_name, 'status' => $u->status] : null,
            ])->all();
            // A room number also finds the reservations staying in it tonight.
            if ($reservations && $term->kind !== 'ref') {
                $known = array_column($out['reservations'], 'id');
                $extra = $units->pluck('reservation_id')->filter()->reject(fn ($id) => in_array($id, $known, true))->unique()->values()->all();
                if ($extra !== []) {
                    $more = $this->reservations(DB::table('reservations')->where('property_id', $pid)->whereIn('public_id', $extra));
                    $out['reservations'] = array_slice([...$more, ...$out['reservations']], 0, self::LIMIT);
                }
            }
        }

        return $out;
    }

    private function reservations($query): array
    {
        return $query->get(['public_id', 'booking_ref', 'guest_name', 'check_in', 'check_out', 'status'])->map(fn ($r) => [
            'id' => $r->public_id, 'ref' => $r->booking_ref, 'guest' => $r->guest_name,
            'check_in' => substr((string) $r->check_in, 0, 10), 'check_out' => substr((string) $r->check_out, 0, 10), 'status' => $r->status,
        ])->all();
    }
}
