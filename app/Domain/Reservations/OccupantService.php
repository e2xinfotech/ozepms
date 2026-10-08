<?php

namespace App\Domain\Reservations;

use App\Domain\Audit\AuditLogger;
use App\Domain\Guests\GuestService;
use App\Domain\Property\AgeBandService;
use App\Infrastructure\Database\Tx;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The people staying in a room besides the main guest: adults, children, infants. All details are
 * optional (only the main guest is required); whatever is entered is kept per reservation room for
 * later checks and the police register. Each person is a guest profile marked is_companion.
 */
class OccupantService
{
    public const MAX_PER_ROOM = 20;

    public function __construct(
        private readonly GuestService $guests,
        private readonly AgeBandService $bands,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Saved people per reservation room (main guest not included) plus the head counts to fill.
     *
     * @return list<array<string, mixed>>
     */
    public function forReservation(Reservation $reservation, bool $showIds): array
    {
        $reservation->loadMissing('rooms.roomType');
        $links = DB::table('reservation_guests as rg')->join('guests as g', 'g.id', '=', 'rg.guest_id')
            ->where('rg.reservation_id', $reservation->id)->where('rg.is_primary', 0)->orderBy('g.id')
            ->get(['rg.reservation_room_id', 'rg.person_type', 'rg.age', 'g.id as gid', 'g.public_id']);
        $guests = Guest::query()->whereIn('id', $links->pluck('gid'))->with('documents')->get()->keyBy('id');

        $out = [];
        foreach ($reservation->rooms->whereNotIn('status', ['cancelled', 'no_show']) as $i => $room) {
            $people = [];
            foreach ($links->where('reservation_room_id', $room->id) as $l) {
                $g = $guests[$l->gid] ?? null;
                if ($g === null) {
                    continue;
                }
                $people[] = [
                    'id' => $g->public_id, 'person_type' => $l->person_type, 'age' => $l->age, 'first_name' => $g->first_name, 'last_name' => $g->last_name,
                    'date_of_birth' => $g->date_of_birth?->toDateString(), 'gender' => $g->gender, 'nationality_iso2' => $g->nationality_iso2, 'id_type' => $g->id_type,
                    'id_number' => $showIds ? $g->id_number_enc : $g->maskedIdNumber(),
                    'documents' => $g->documents->map(fn ($d) => ['id' => $d->public_id, 'type' => $d->doc_type, 'name' => $d->file_name])->values()->all(),
                ];
            }
            $out[] = [
                'room_id' => $room->public_id, 'index' => $i + 1, 'room_type' => $room->roomType?->name,
                'adults' => (int) $room->adults, 'children' => (int) $room->children, 'infants' => (int) $room->infants,
                'people' => $people,
            ];
        }

        return $out;
    }

    /**
     * Replaces the saved companions of one room with $rows. Rows without any data are ignored;
     * a row needs a first name as soon as anything else is given. Returns the saved people in order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, person_type: string}>
     */
    public function save(Reservation $reservation, ReservationRoom $room, array $rows, ?User $by = null): array
    {
        if ((int) $room->reservation_id !== (int) $reservation->id || in_array($room->status, ['cancelled', 'no_show'], true)) {
            throw ValidationException::withMessages(['room_id' => __('reservations.errors.not_assignable')]);
        }
        $property = Property::query()->findOrFail($reservation->property_id);
        $bands = $this->bands->bands($property->id);
        $clean = [];
        foreach (array_values($rows) as $i => $row) {
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
            $data = array_filter(array_intersect_key($row, array_flip(['first_name', 'last_name', 'date_of_birth', 'gender', 'nationality_iso2', 'id_type', 'id_number'])), fn ($v) => $v !== null && $v !== '');
            $age = isset($row['age']) && $row['age'] !== '' ? (int) $row['age'] : null;
            if ($data === [] && $age === null) {
                continue;
            }
            if (empty($data['first_name'])) {
                throw ValidationException::withMessages(["occupants.$i.first_name" => __('reservations.occupants.name_needed')]);
            }
            $type = in_array($row['person_type'] ?? 'adult', ['adult', 'child', 'infant'], true) ? $row['person_type'] : 'adult';
            if ($age !== null && $type === 'infant' && $age > $bands['infant']['max']) {
                throw ValidationException::withMessages(["occupants.$i.age" => __('reservations.occupants.age_infant', $bands['infant'])]);
            }
            if ($age !== null && $type === 'child' && ($age < $bands['child']['min'] || $age > $bands['child']['max'])) {
                throw ValidationException::withMessages(["occupants.$i.age" => __('reservations.occupants.age_child', $bands['child'])]);
            }
            if ($age !== null && $type === 'adult' && $age <= $bands['child']['max']) {
                throw ValidationException::withMessages(["occupants.$i.age" => __('reservations.occupants.age_adult', ['min' => $bands['child']['max'] + 1])]);
            }
            $clean[] = ['id' => $row['id'] ?? null, 'type' => $type, 'age' => $age, 'data' => $data + ['gender' => $row['gender'] ?? null]];
        }
        if (count($clean) > self::MAX_PER_ROOM) {
            throw ValidationException::withMessages(['occupants' => __('reservations.occupants.too_many')]);
        }

        return Tx::run(function () use ($reservation, $room, $clean, $property, $by) {
            $existing = DB::table('reservation_guests as rg')->join('guests as g', 'g.id', '=', 'rg.guest_id')
                ->where('rg.reservation_id', $reservation->id)->where('rg.reservation_room_id', $room->id)->where('rg.is_primary', 0)
                ->pluck('g.public_id', 'g.id')->all();
            $kept = [];
            $saved = [];
            foreach ($clean as $row) {
                $guest = $row['id'] !== null ? Guest::query()->where('public_id', $row['id'])->whereIn('id', array_keys($existing))->first() : null;
                if ($guest === null) {
                    $guest = $this->guests->create($property, $row['data'], $by);
                    $guest->forceFill(['is_companion' => true])->save();
                } else {
                    $guest = $this->guests->update($guest, $row['data'] + ['date_of_birth' => $row['data']['date_of_birth'] ?? null], $by);
                }
                $kept[$guest->id] = true;
                DB::table('reservation_guests')->updateOrInsert(
                    ['reservation_id' => $reservation->id, 'guest_id' => $guest->id],
                    ['reservation_room_id' => $room->id, 'is_primary' => 0, 'person_type' => $row['type'], 'age' => $row['age']],
                );
                $saved[] = ['id' => $guest->public_id, 'person_type' => $row['type']];
            }
            foreach (array_diff_key($existing, $kept) as $guestId => $publicId) {
                DB::table('reservation_guests')->where('reservation_id', $reservation->id)->where('guest_id', $guestId)->delete();
                $orphan = ! DB::table('reservation_guests')->where('guest_id', $guestId)->exists() && ! DB::table('guest_documents')->where('guest_id', $guestId)->exists();
                if ($orphan) {
                    Guest::query()->whereKey($guestId)->where('is_companion', 1)->delete();
                }
            }
            $this->audit->log('reservation.occupants_saved', $reservation, ['after' => ['room' => $room->public_id, 'people' => count($saved)]], $reservation->property_id, $by?->id);

            return $saved;
        });
    }
}
