<?php

namespace App\Domain\Accommodation;

use App\Domain\Audit\AuditLogger;
use App\Models\Amenity;
use App\Models\PhysicalUnit;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Amenities of one PMS room. All amenities live in the single central amenities table;
 * a room inherits its room type's amenities and only the differences are stored
 * (physical_unit_amenities: mode "add" = extra in this room, "remove" = missing in this room).
 */
class UnitAmenityService
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Saves the full list of amenities the room should have.
     *
     * @param  array<int, string>  $codes  amenity codes selected for the room
     */
    public function sync(PhysicalUnit $unit, array $codes): void
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        // Only in-room amenities can differ per room; property facilities stay as set on the room type.
        $selected = Amenity::query()->visibleToProperty($this->context->id())->where('is_active', true)
            ->whereRaw('FIND_IN_SET(?, applies_to)', ['unit'])
            ->whereIn('code', $codes)->pluck('id', 'code');

        if ($selected->count() !== count($codes)) {
            throw ValidationException::withMessages(['amenities' => __('rooms.errors.amenity_unknown')]);
        }

        $inherited = DB::table('room_type_amenities')
            ->join('amenities', 'amenities.id', '=', 'room_type_amenities.amenity_id')
            ->where('room_type_amenities.room_type_id', $unit->room_type_id)
            ->whereRaw('FIND_IN_SET(?, amenities.applies_to)', ['unit'])
            ->pluck('room_type_amenities.amenity_id')->all();
        $chosen = $selected->values()->all();

        $rows = [];
        foreach (array_diff($chosen, $inherited) as $id) {
            $rows[] = ['unit_id' => $unit->id, 'amenity_id' => $id, 'mode' => 'add'];
        }
        foreach (array_diff($inherited, $chosen) as $id) {
            $rows[] = ['unit_id' => $unit->id, 'amenity_id' => $id, 'mode' => 'remove'];
        }

        $before = DB::table('physical_unit_amenities')->where('unit_id', $unit->id)->orderBy('amenity_id')->get(['amenity_id', 'mode'])->map(fn ($r) => (array) $r)->all();
        usort($rows, fn ($a, $b) => $a['amenity_id'] <=> $b['amenity_id']);
        $after = array_map(fn ($r) => ['amenity_id' => $r['amenity_id'], 'mode' => $r['mode']], $rows);
        if ($before === $after) {
            return;
        }

        DB::table('physical_unit_amenities')->where('unit_id', $unit->id)->delete();
        if ($rows) {
            DB::table('physical_unit_amenities')->insert($rows);
        }
        $this->audit->log('physical_unit.amenities_changed', $unit, ['after' => ['amenities' => $codes]]);
    }

    /**
     * Amenities the room actually has (room type amenities + room extras − room exclusions).
     *
     * @return array<int, array{code: string, label: string, icon: ?string, source: string}>
     */
    public function effective(PhysicalUnit $unit): array
    {
        $typeIds = DB::table('room_type_amenities')->where('room_type_id', $unit->room_type_id)->pluck('amenity_id')->all();
        $overrides = DB::table('physical_unit_amenities')->where('unit_id', $unit->id)->pluck('mode', 'amenity_id')->all();

        $ids = array_values(array_unique(array_merge(
            array_filter($typeIds, fn ($id) => ($overrides[$id] ?? null) !== 'remove'),
            array_keys(array_filter($overrides, fn ($m) => $m === 'add')),
        )));

        return Amenity::query()->whereIn('id', $ids)->orderBy('category')->orderBy('id')->get()
            ->map(fn (Amenity $a) => [
                'code' => $a->code,
                'label' => $a->label(),
                'icon' => $a->icon,
                'source' => ($overrides[$a->id] ?? null) === 'add' ? 'room' : 'room_type',
            ])->all();
    }
}
