<?php

namespace App\Http\Resources\Accommodation;

use App\Domain\Rates\Queries\RatePlanQuery;
use App\Models\Product;
use App\Models\RatePlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RatePlan */
class RatePlanResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var RatePlan $p */
        $p = $this->resource;
        $p->loadMissing(['mealPlan', 'cancellationPolicy.rules', 'products.roomType', 'products.ratePlan', 'products.parent.ratePlan', 'products.occupancyRules']);
        $query = app(RatePlanQuery::class);

        return $query->row($p) + [
            'payment_type' => $p->payment_type,
            'deposit_value' => $p->deposit_value,
            'default_max_los' => $p->default_max_los,
            'min_advance_days' => $p->min_advance_days,
            'max_advance_days' => $p->max_advance_days,
            'sell_on_pms' => $p->sell_on_pms,
            'sell_on_booking_engine' => $p->sell_on_booking_engine,
            'sell_on_channels' => $p->sell_on_channels,
            'sort_order' => $p->sort_order,
            'mapped_products' => $query->mappedProducts($p),
            'cancellation_policy' => $p->cancellationPolicy ? [
                'code' => $p->cancellationPolicy->code,
                'name' => $p->cancellationPolicy->name,
                'refundable' => $p->cancellationPolicy->is_refundable,
                'description' => $p->cancellationPolicy->description,
                'rules' => $p->cancellationPolicy->rules->map(fn ($r) => [
                    'applies_to' => $r->applies_to, 'hours_before_arrival' => $r->hours_before_arrival,
                    'charge_type' => $r->charge_type, 'charge_value' => $r->charge_value,
                ])->values()->all(),
            ] : null,
            'products' => $p->products->sortBy(fn (Product $x) => [$x->roomType?->sort_order, $x->roomType?->name])
                ->map(fn (Product $x) => ProductResource::shape($x))->values()->all(),
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }
}
