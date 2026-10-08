<?php

namespace App\Domain\Guests\Queries;

use App\Domain\Accommodation\UnitNightGuard;
use App\Domain\Access\AccessService;
use App\Domain\Guests\GuestService;
use App\Domain\Reservations\Queries\SearchTerm;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\Note;
use App\Models\Property;
use App\Models\User;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Guests list (design guests.png) and the profile panel. Search uses the guest indexes
 * (email_lc, phone_rev, ngram FULLTEXT, HMAC of the ID number); stay figures come from
 * reservations by ix_res_guest for the rows of the page only.
 */
class GuestQuery
{
    public const TABS = ['all', 'in_house', 'upcoming', 'past'];

    public const SORTS = ['number' => 'guests.guest_no', 'name' => 'guests.last_name', 'created' => 'guests.created_at', 'nationality' => 'guests.nationality_iso2'];

    public function __construct(
        private readonly UnitNightGuard $guard,
        private readonly GuestService $guests,
        private readonly AccessService $access,
    ) {}

    public function list(Property $property, Request $request, ?User $user): array
    {
        $f = Listing::filters($request, ['q', 'nationality', 'type', 'vip', 'from', 'to', 'tab']);
        $tab = in_array($f['tab'], self::TABS, true) ? $f['tab'] : 'all';
        $today = $this->guard->today($property);

        $filtered = Guest::query()->whereNull('guests.anonymized_at')->where('guests.is_companion', 0);
        if ($f['q'] !== '' && ($term = SearchTerm::parse($f['q'])) !== null) {
            $filtered->where(function (Builder $w) use ($term, $property) {
                match ($term->kind) {
                    'email' => $w->where('guests.email_lc', 'like', SearchTerm::prefix($term->value)),
                    'phone' => $w->where('guests.phone_rev', 'like', SearchTerm::prefix(strrev($term->value))),
                    default => $term->fulltext() !== ''
                        ? $w->whereRaw('MATCH (guests.first_name, guests.last_name, guests.email) AGAINST (? IN BOOLEAN MODE)', [$term->fulltext()])
                        : $w->where('guests.first_name', 'like', SearchTerm::prefix($term->value)),
                };
                // ID numbers are encrypted: exact match through the blind index.
                $w->orWhere(fn ($x) => $x->where('guests.property_id', $property->id)->where('guests.id_number_hash', $this->guests->idHash($term->raw)));
                if (preg_match('/^G-?0*(\d+)$/i', $term->raw, $m)) {
                    $w->orWhere('guests.guest_no', (int) $m[1]);
                }
            });
        }
        $filtered->when($f['nationality'] !== '', fn ($q) => $q->where('guests.nationality_iso2', strtoupper($f['nationality'])))
            ->when(in_array($f['type'], Guest::TYPES, true), fn ($q) => $q->where('guests.guest_type', $f['type']))
            ->when($f['vip'] === '1', fn ($q) => $q->where('guests.is_vip', true))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from']), fn ($q) => $q->where('guests.created_at', '>=', $f['from'].' 00:00:00'))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to']), fn ($q) => $q->where('guests.created_at', '<=', $f['to'].' 23:59:59'));

        $counts = ['all' => (clone $filtered)->count()];
        foreach (['in_house', 'upcoming', 'past'] as $t) {
            $counts[$t] = (clone $filtered)->whereExists($this->stayExists($t, $today))->count();
        }

        $rows = (clone $filtered)->select('guests.*');
        if ($tab !== 'all') {
            $rows->whereExists($this->stayExists($tab, $today));
        }
        Listing::sort($rows, $request, self::SORTS, 'guests.created_at', 'desc');
        $rows->orderByDesc('guests.id');

        $page = Listing::paginate($rows, $request);
        $stats = $this->stats(collect($page['rows'])->pluck('id')->all(), $today);
        $canSeeId = $user !== null && $this->access->allows($user, 'guests.update');
        $page['rows'] = array_map(fn (Guest $g) => $this->row($g, $stats[$g->id] ?? null, $canSeeId), $page['rows']);

        return $page + ['counts' => $counts];
    }

    /** Profile for the side panel: details, stay history (latest 10), notes, documents. */
    public function profile(Guest $guest, Property $property, ?User $user): array
    {
        $today = $this->guard->today($property);
        $stats = $this->stats([$guest->id], $today)[$guest->id] ?? null;
        $canSeeId = $user !== null && $this->access->allows($user, 'guests.update');
        $stays = DB::table('reservations')->where('reservations.property_id', $property->id)->where('reservations.primary_guest_id', $guest->id)
            ->orderByDesc('reservations.check_in')->limit(10)
            ->get(['reservations.id', 'reservations.public_id', 'reservations.booking_ref', 'reservations.check_in', 'reservations.check_out', 'reservations.nights',
                'reservations.status', 'reservations.grand_total', 'reservations.currency_code']);
        $firstRooms = DB::table('reservation_rooms')
            ->join('rate_plans', 'rate_plans.id', '=', 'reservation_rooms.rate_plan_id')
            ->join('room_types', 'room_types.id', '=', 'reservation_rooms.room_type_id')
            ->leftJoin('unit_nights', fn ($j) => $j->on('unit_nights.reservation_room_id', '=', 'reservation_rooms.id')->whereColumn('unit_nights.stay_date', 'reservation_rooms.check_in'))
            ->leftJoin('physical_units', 'physical_units.id', '=', 'unit_nights.unit_id')
            ->whereIn('reservation_rooms.reservation_id', $stays->pluck('id'))->orderBy('reservation_rooms.sort_order')
            ->get(['reservation_rooms.reservation_id', 'rate_plans.name as rate_plan', 'room_types.name as room_type', 'physical_units.name as unit'])
            ->groupBy('reservation_id');

        return $this->row($guest, $stats, $canSeeId) + [
            'title' => $guest->title, 'first_name' => $guest->first_name, 'last_name' => $guest->last_name,
            'country' => $guest->country_iso2, 'date_of_birth' => $guest->date_of_birth?->toDateString(),
            'address_line1' => $guest->address_line1, 'address_line2' => $guest->address_line2, 'city' => $guest->city, 'postcode' => $guest->postcode,
            'company_name' => $guest->company_name, 'company_tax_no' => $guest->company_tax_no,
            'id_issuing' => $guest->id_issuing_iso2, 'id_expiry' => $guest->id_expiry?->toDateString(),
            'marketing_consent' => (bool) $guest->marketing_consent, 'notes_text' => $guest->notes, 'preferences' => $guest->preferences,
            'stay_history' => $stays->map(function ($s) use ($firstRooms) {
                $room = $firstRooms->get($s->id)?->first();

                return [
                    'id' => $s->public_id, 'ref' => $s->booking_ref, 'check_in' => substr((string) $s->check_in, 0, 10), 'check_out' => substr((string) $s->check_out, 0, 10),
                    'nights' => (int) $s->nights, 'status' => $s->status, 'total' => (string) $s->grand_total, 'currency' => $s->currency_code,
                    'unit' => $room?->unit, 'room_type' => $room?->room_type, 'rate_plan' => $room?->rate_plan,
                ];
            })->all(),
            'notes' => Note::query()->where('subject_type', 'guest')->where('subject_id', $guest->id)->with('user:id,name')->orderByDesc('id')->limit(50)->get()
                ->map(fn (Note $n) => ['id' => $n->id, 'body' => $n->body, 'user' => $n->user?->name, 'at' => $n->created_at?->toIso8601String()])->all(),
            'documents' => GuestDocument::query()->where('guest_id', $guest->id)->orderByDesc('id')->limit(50)->get()
                ->map(fn (GuestDocument $d) => ['id' => $d->public_id, 'type' => $d->doc_type, 'name' => $d->file_name, 'size' => (int) $d->size_bytes, 'mime' => $d->mime, 'at' => $d->created_at?->toIso8601String()])->all(),
        ];
    }

    public function row(Guest $g, ?object $stats, bool $canSeeId): array
    {
        $status = match (true) {
            (int) ($stats->in_house ?? 0) > 0 => 'in_house',
            (int) ($stats->upcoming ?? 0) > 0 => 'upcoming',
            (int) ($stats->stays ?? 0) > 0 => 'past',
            default => 'new',
        };

        return [
            'id' => $g->public_id,
            'number' => $g->number(),
            'name' => $g->fullName(),
            'email' => $g->email,
            'phone' => $g->phone_e164,
            'nationality' => $g->nationality_iso2,
            'guest_type' => $g->guest_type,
            'id_type' => $g->id_type,
            'id_number' => $canSeeId ? $g->id_number_enc : $g->maskedIdNumber(),
            'vip' => (bool) $g->is_vip,
            'tags' => $g->tags ?? [],
            'stays' => (int) ($stats->stays ?? 0),
            'last_stay' => $stats->last_stay ?? null,
            'status' => $status,
            'created_at' => $g->created_at?->toIso8601String(),
        ];
    }

    private function stayExists(string $tab, string $today): \Closure
    {
        return fn ($q) => $q->from('reservations as rs')->whereColumn('rs.property_id', 'guests.property_id')->whereColumn('rs.primary_guest_id', 'guests.id')
            ->when($tab === 'in_house', fn ($x) => $x->where('rs.status', 'checked_in'))
            ->when($tab === 'upcoming', fn ($x) => $x->whereIn('rs.status', ['pending', 'confirmed'])->where('rs.check_in', '>=', $today))
            ->when($tab === 'past', fn ($x) => $x->where('rs.status', 'checked_out'));
    }

    /** @param  list<int>  $ids  @return array<int, object> */
    private function stats(array $ids, string $today): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('reservations')->where('property_id', app(\App\Support\PropertyContext::class)->id())->whereIn('primary_guest_id', $ids)
            ->groupBy('primary_guest_id')
            ->selectRaw("primary_guest_id,
                SUM(status IN ('checked_in','checked_out')) AS stays,
                SUM(status = 'checked_in') AS in_house,
                SUM(status IN ('pending','confirmed') AND check_in >= ?) AS upcoming,
                MAX(CASE WHEN status IN ('checked_in','checked_out') THEN check_in END) AS last_stay", [$today])
            ->get()->keyBy('primary_guest_id')->all();
    }
}
