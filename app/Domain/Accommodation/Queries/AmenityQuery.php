<?php

namespace App\Domain\Accommodation\Queries;

use App\Models\Amenity;
use App\Support\Listing;
use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Amenities list: the global catalogue plus the property's custom amenities, with usage counts. */
class AmenityQuery
{
    public const TABS = ['all', 'global', 'custom'];

    public function __construct(private readonly PropertyContext $context) {}

    /** @return array{rows: array, meta: array, counts: array} */
    public function list(Request $request): array
    {
        $propertyId = $this->context->id();
        $filters = Listing::filters($request, ['q', 'category', 'tab']);
        $tab = in_array($filters['tab'], self::TABS, true) ? $filters['tab'] : 'all';

        $filtered = Amenity::query()->visibleToProperty($propertyId)
            ->when($filters['category'] !== '', fn (Builder $q) => $q->where('category', $filters['category']))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                // Global amenities are matched on their translated label too.
                $term = mb_strtolower($filters['q']);
                $codes = collect(trans('amenities.items'))->filter(fn ($label) => is_string($label) && str_contains(mb_strtolower($label), $term))->keys()->all();
                $q->where(fn (Builder $w) => $w->where('name', 'like', '%'.$filters['q'].'%')
                    ->orWhere(fn (Builder $g) => $g->whereNull('property_id')->whereIn('code', $codes)));
            });

        $counts = (clone $filtered)->toBase()->selectRaw('COUNT(*) AS total, SUM(property_id IS NOT NULL) AS custom')->first();

        $usage = DB::table('room_type_amenities')
            ->join('room_types', 'room_types.id', '=', 'room_type_amenities.room_type_id')
            ->where('room_types.property_id', $propertyId)
            ->whereNull('room_types.deleted_at')
            ->whereColumn('room_type_amenities.amenity_id', 'amenities.id')
            ->selectRaw('COUNT(*)');

        $facility = DB::table('property_amenities')->where('property_amenities.property_id', $propertyId)
            ->whereColumn('property_amenities.amenity_id', 'amenities.id')->selectRaw('COUNT(*)');

        $rows = (clone $filtered)->select('amenities.*')->selectSub($usage, 'room_types_count')->selectSub($facility, 'is_facility')
            ->when($tab === 'global', fn (Builder $q) => $q->whereNull('property_id'))
            ->when($tab === 'custom', fn (Builder $q) => $q->whereNotNull('property_id'))
            ->orderBy('category')->orderByRaw('property_id IS NULL DESC')->orderBy('id');

        $total = (int) ($counts->total ?? 0);
        $custom = (int) ($counts->custom ?? 0);

        return Listing::paginate($rows, $request, fn (Amenity $a) => $this->row($a)) + [
            'counts' => ['all' => $total, 'global' => $total - $custom, 'custom' => $custom],
        ];
    }

    /** @return array<string, mixed> */
    public function row(Amenity $a): array
    {
        return [
            'id' => $a->code,
            'name' => $a->label(),
            'raw_name' => $a->name,
            'category' => $a->category,
            'icon' => $a->icon,
            'custom' => $a->isCustom(),
            'is_active' => $a->is_active,
            'room_types_count' => (int) ($a->getAttributes()['room_types_count'] ?? 0),
            // null: the amenity belongs in rooms and cannot be a property facility.
            'property_facility' => in_array('property', explode(',', (string) $a->applies_to), true)
                ? (array_key_exists('is_facility', $a->getAttributes()) ? (int) $a->getAttributes()['is_facility'] > 0 : DB::table('property_amenities')->where('property_id', $this->context->id())->where('amenity_id', $a->id)->exists())
                : null,
        ];
    }
}
