<?php

namespace App\Domain\Accommodation\Queries;

use App\Models\Product;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Room types list: filters, status counts and one page of rows. */
class RoomTypeQuery
{
    public const SORTS = [
        'name' => 'room_types.name', 'code' => 'room_types.code', 'category' => 'room_types.category',
        'status' => 'room_types.is_active', 'default_rate_plan' => 'room_types.sort_order', 'order' => 'room_types.sort_order',
    ];

    /** @return array{rows: array, meta: array, counts: array} */
    public function list(Request $request): array
    {
        $filters = Listing::filters($request, ['q', 'status', 'category', 'meal_plan']);
        $filtered = $this->filtered($filters);

        $counts = (clone $filtered)->toBase()->selectRaw('COUNT(*) AS total, SUM(is_active = 1) AS active')->first();

        $rows = (clone $filtered)
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('room_types.is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('room_types.is_active', false))
            ->withCount(['units as total_rooms', 'units as active_rooms' => fn ($q) => $q->where('is_active', true)])
            ->with([
                'images' => fn ($q) => $q->select(['id', 'property_id', 'room_type_id', 'path', 'sort_order']),
                'products' => fn ($q) => $q->where('is_active', true)->with(['ratePlan.mealPlan', 'parent', 'roomType']),
            ]);
        Listing::sort($rows, $request, self::SORTS, 'room_types.sort_order');
        $rows->orderBy('room_types.id');

        return Listing::paginate($rows, $request, fn (RoomType $r) => $this->row($r)) + [
            'counts' => [
                'all' => (int) ($counts->total ?? 0),
                'active' => (int) ($counts->active ?? 0),
                'inactive' => (int) ($counts->total ?? 0) - (int) ($counts->active ?? 0),
            ],
        ];
    }

    /** @param  array<string, string>  $filters */
    private function filtered(array $filters): Builder
    {
        return RoomType::query()
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn (Builder $w) => $w->where('room_types.name', 'like', $term)->orWhere('room_types.code', 'like', $term));
            })
            ->when($filters['category'] !== '', fn (Builder $q) => $q->where('room_types.category', $filters['category']))
            ->when($filters['meal_plan'] !== '', fn (Builder $q) => $q->whereHas('products', fn ($p) => $p->where('is_active', true)
                ->whereHas('ratePlan', fn ($rp) => $rp->whereHas('mealPlan', fn ($m) => $m->where('code', $filters['meal_plan'])))));
    }

    /** @return array<string, mixed> */
    public function row(RoomType $r): array
    {
        $image = $r->images->first();
        $default = $r->products->firstWhere('is_default', true) ?? $r->products->first();

        return [
            'id' => $r->public_id,
            'name' => $r->name,
            'code' => $r->code,
            'category' => $r->category,
            'description' => $r->description ? Str::limit(strip_tags($r->description), 90) : null,
            'image' => $image instanceof RoomTypeImage ? $image->url() : null,
            'base_adults' => $r->base_adults,
            'max_adults' => $r->max_adults,
            'max_children' => $r->max_children,
            'max_occupancy' => $r->max_occupancy,
            'total_rooms' => (int) $r->total_rooms,
            'active_rooms' => (int) $r->active_rooms,
            'is_active' => $r->is_active,
            'default_product' => $default?->public_id,
            'products' => $r->products->map(fn (Product $p) => [
                'id' => $p->public_id,
                'rate_plan' => $p->ratePlan?->public_id,
                'label' => $p->ratePlan ? $p->ratePlan->name.' ('.$p->ratePlan->code.')' : '—',
                'meal_plan' => $p->ratePlan?->mealPlan?->code,
                'is_default' => $p->is_default,
            ])->values()->all(),
        ];
    }
}
