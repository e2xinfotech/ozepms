<?php

namespace App\Domain\Rates\Queries;

use App\Domain\Rates\DerivedPrice;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Support\Listing;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rate plans list (v2 layout): KPI tiles All / Active / Inactive / Channel mapped / Not mapped,
 * filters and one page of rows. "Channel mapped" = at least one product of the plan is
 * mapped to a channel (channel_rate_plan_mappings, maintained by the channel manager).
 */
class RatePlanQuery
{
    public const TABS = ['all', 'active', 'inactive', 'mapped', 'unmapped'];

    public const SORTS = ['name' => 'rate_plans.name', 'code' => 'rate_plans.code', 'min_los' => 'rate_plans.default_min_los', 'status' => 'rate_plans.is_active', 'order' => 'rate_plans.sort_order'];

    /** @return array{rows: array, meta: array, counts: array} */
    public function list(Request $request): array
    {
        $filters = Listing::filters($request, ['q', 'room_type', 'meal_plan', 'status', 'tab']);
        $tab = in_array($filters['tab'], self::TABS, true) ? $filters['tab'] : 'all';

        $filtered = RatePlan::query()
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn (Builder $w) => $w->where('rate_plans.name', 'like', $term)->orWhere('rate_plans.code', 'like', $term));
            })
            ->when($filters['meal_plan'] !== '', fn (Builder $q) => $q->whereHas('mealPlan', fn ($m) => $m->where('code', $filters['meal_plan'])))
            ->when($filters['room_type'] !== '', fn (Builder $q) => $q->whereHas('products', fn ($p) => $p->where('is_active', true)
                ->whereHas('roomType', fn ($r) => $r->where('public_id', $filters['room_type']))))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('rate_plans.is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('rate_plans.is_active', false));

        $mappedSql = 'EXISTS (SELECT 1 FROM channel_rate_plan_mappings m JOIN room_type_rate_plans p ON p.id = m.product_id WHERE p.rate_plan_id = rate_plans.id)';
        $counts = (clone $filtered)->toBase()
            ->selectRaw("COUNT(*) AS total, SUM(is_active = 1) AS active, SUM($mappedSql) AS mapped")->first();

        $rows = (clone $filtered)
            ->with(['mealPlan', 'cancellationPolicy', 'products' => fn ($q) => $q->where('is_active', true)->with(['roomType', 'parent.roomType', 'parent.parent'])])
            ->selectRaw("rate_plans.*, ($mappedSql) AS is_mapped");
        match ($tab) {
            'active' => $rows->where('rate_plans.is_active', true),
            'inactive' => $rows->where('rate_plans.is_active', false),
            'mapped' => $rows->whereRaw($mappedSql),
            'unmapped' => $rows->whereRaw("NOT $mappedSql"),
            default => null,
        };
        Listing::sort($rows, $request, self::SORTS, 'rate_plans.sort_order');
        $rows->orderBy('rate_plans.id');

        $activeRoomTypes = RoomType::query()->where('is_active', true)->count();
        $total = (int) ($counts->total ?? 0);
        $active = (int) ($counts->active ?? 0);
        $mapped = (int) ($counts->mapped ?? 0);

        return Listing::paginate($rows, $request, fn (RatePlan $p) => $this->row($p, $activeRoomTypes)) + [
            'counts' => ['all' => $total, 'active' => $active, 'inactive' => $total - $active, 'mapped' => $mapped, 'unmapped' => $total - $mapped],
        ];
    }

    /** @return array<string, mixed> */
    public function row(RatePlan $p, ?int $activeRoomTypes = null): array
    {
        $products = $p->products->where('is_active', true);
        $codes = $products->map(fn (Product $x) => $x->roomType?->code)->filter()->unique()->values();
        $activeRoomTypes ??= RoomType::query()->where('is_active', true)->count();

        $prices = $products->map(fn (Product $x) => DerivedPrice::basePrice($x))->filter();
        $base = $prices->reduce(fn (?string $carry, string $price) => $carry === null || Money::compare($price, $carry) < 0 ? $price : $carry);

        return [
            'id' => $p->public_id,
            'name' => $p->name,
            'code' => $p->code,
            'description' => $p->description,
            'meal_plan' => $p->mealPlan ? ['code' => $p->mealPlan->code, 'label' => $p->mealPlan->label()] : null,
            'room_types' => $codes->all(),
            'all_room_types' => $activeRoomTypes > 0 && $codes->count() >= $activeRoomTypes,
            'policy' => $p->cancellationPolicy ? ['code' => $p->cancellationPolicy->code, 'name' => $p->cancellationPolicy->name, 'refundable' => $p->cancellationPolicy->is_refundable] : null,
            'base_rate' => $base,
            'min_los' => $p->default_min_los,
            'channels' => ['pms' => $p->sell_on_pms, 'booking_engine' => $p->sell_on_booking_engine, 'channels' => $p->sell_on_channels],
            'is_mapped' => (bool) ($p->is_mapped ?? false),
            'is_active' => $p->is_active,
            'is_default' => $p->is_default,
        ];
    }

    /** Channel mapping count for one plan (side panel). */
    public function mappedProducts(RatePlan $plan): int
    {
        return (int) DB::table('channel_rate_plan_mappings')
            ->join('room_type_rate_plans', 'room_type_rate_plans.id', '=', 'channel_rate_plan_mappings.product_id')
            ->where('room_type_rate_plans.rate_plan_id', $plan->id)
            ->count();
    }
}
