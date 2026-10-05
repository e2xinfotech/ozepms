<?php

namespace App\Domain\Property\Queries;

use App\Models\Property;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Filtered, paginated property lists: every property (platform) or only the
 * properties a user belongs to (property workspace "Properties" page).
 */
class PropertyListQuery
{
    public const FILTERS = ['q', 'country', 'type', 'status'];

    public const STATUSES = ['onboarding', 'active', 'suspended', 'inactive'];

    /** @return array{rows: array, meta: array, counts: array, kpis: array} */
    public function platform(Request $request): array
    {
        return $this->run(Property::query(), $request);
    }

    /** @return array{rows: array, meta: array, counts: array, kpis: array} */
    public function forUser(int $userId, Request $request): array
    {
        $base = Property::query()->whereIn('properties.id', DB::table('property_users')
            ->select('property_id')->where('user_id', $userId)->where('status', 'active'));

        return $this->run($base, $request);
    }

    /** Distinct countries of the given list, for the country filter. */
    public function countryOptions(?int $userId = null): array
    {
        return Property::query()
            ->join('countries', 'countries.iso2', '=', 'properties.country_iso2')
            ->when($userId, fn ($q) => $q->whereIn('properties.id', DB::table('property_users')
                ->select('property_id')->where('user_id', $userId)->where('status', 'active')))
            ->distinct()->orderBy('countries.name')
            ->get(['countries.iso2', 'countries.name'])
            ->map(fn ($c) => ['value' => $c->iso2, 'label' => $c->name])->all();
    }

    private function run(Builder $base, Request $request): array
    {
        $filters = Listing::filters($request, self::FILTERS);

        $filtered = (clone $base)
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn (Builder $w) => $w->where('properties.name', 'like', $term)
                    ->orWhere('properties.code', 'like', $term)
                    ->orWhere('properties.city', 'like', $term));
            })
            ->when($filters['country'] !== '', fn (Builder $q) => $q->where('properties.country_iso2', $filters['country']))
            ->when($filters['type'] !== '', fn (Builder $q) => $q->whereHas('type', fn (Builder $t) => $t->where('code', $filters['type'])));

        $byStatus = (clone $filtered)->reorder()->select('properties.status', DB::raw('count(*) as total'))
            ->groupBy('properties.status')->pluck('total', 'status');

        $rows = (clone $filtered)
            ->when(in_array($filters['status'], self::STATUSES, true), fn (Builder $q) => $q->where('properties.status', $filters['status']))
            ->with(['type:id,code,label_key', 'country:iso2,name', 'currentSubscription.plan:id,name'])
            ->select('properties.*')
            ->selectSub($this->roomCount(), 'rooms_count');

        Listing::sort($rows, $request, [
            'name' => 'properties.name', 'code' => 'properties.id', 'rooms' => 'rooms_count', 'status' => 'properties.status',
        ], 'properties.name');

        $all = (int) $byStatus->sum();

        return Listing::paginate($rows, $request, fn (Property $p) => $this->row($p)) + [
            'counts' => ['all' => $all] + collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)])->all(),
            'kpis' => [
                'total' => $all,
                'active' => (int) ($byStatus['active'] ?? 0),
                'inactive' => (int) (($byStatus['inactive'] ?? 0) + ($byStatus['suspended'] ?? 0)),
                'setup' => (int) ($byStatus['onboarding'] ?? 0),
                'countries' => (clone $filtered)->reorder()->distinct()->count('properties.country_iso2'),
                'rooms' => (int) DB::table('physical_units')->whereNull('deleted_at')->where('is_active', true)
                    ->whereIn('property_id', (clone $filtered)->reorder()->select('properties.id'))->count(),
            ],
        ];
    }

    private function roomCount(): \Illuminate\Database\Query\Builder
    {
        return DB::table('physical_units')->selectRaw('count(*)')
            ->whereColumn('physical_units.property_id', 'properties.id')
            ->whereNull('physical_units.deleted_at')
            ->where('physical_units.is_active', true);
    }

    /** One list row. Codes, never internal ids. */
    public function row(Property $p): array
    {
        return [
            'code' => $p->code,
            'name' => $p->name,
            'tagline' => $p->tagline,
            'city' => $p->city,
            'country' => $p->country?->name,
            'country_code' => $p->country_iso2,
            'location' => $p->locationLabel(),
            'type' => $p->type?->code,
            'type_label' => $p->type ? __($p->type->label_key) : null,
            'rooms' => (int) ($p->rooms_count ?? 0),
            'status' => $p->status,
            'star_rating' => $p->star_rating,
            'image' => $p->cover_image_path ? Storage::url($p->cover_image_path) : null,
            'plan' => $p->currentSubscription?->plan?->name,
            'subscription_status' => $p->currentSubscription?->status ?? 'none',
        ];
    }
}
