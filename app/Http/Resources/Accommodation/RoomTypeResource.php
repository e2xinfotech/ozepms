<?php

namespace App\Http\Resources\Accommodation;

use App\Models\Product;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A room type with everything the edit form and detail views need:
 * beds, amenities, images, PMS rooms and its rate plan links (products).
 *
 * @mixin RoomType
 */
class RoomTypeResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var RoomType $r */
        $r = $this->resource;
        $r->loadMissing(['beds', 'amenities', 'images', 'units', 'products.ratePlan', 'products.parent.ratePlan', 'products.occupancyRules']);

        return [
            'id' => $r->public_id,
            'code' => $r->code,
            'name' => $r->name,
            'category' => $r->category,
            'description' => $r->description,
            'base_adults' => $r->base_adults,
            'max_adults' => $r->max_adults,
            'max_children' => $r->max_children,
            'max_infants' => $r->max_infants,
            'max_occupancy' => $r->max_occupancy,
            'extra_bed_allowed' => $r->extra_bed_allowed,
            'max_extra_beds' => $r->max_extra_beds,
            'size_value' => $r->size_value,
            'size_unit' => $r->size_unit,
            'smoking_policy' => $r->smoking_policy,
            'view_label' => $r->view_label,
            'sort_order' => $r->sort_order,
            'is_active' => $r->is_active,
            'beds' => $r->beds->map(fn ($b) => ['bed_type' => $b->code, 'label' => $b->label(), 'quantity' => (int) $b->pivot->quantity])->values()->all(),
            'amenities' => $r->amenities->pluck('code')->values()->all(),
            'images' => $r->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url(), 'alt' => $i->alt_text])->values()->all(),
            'units' => $r->units->map(fn ($u) => [
                'id' => $u->public_id, 'name' => $u->name, 'floor' => $u->floor, 'is_active' => $u->is_active,
                'housekeeping_status' => $u->housekeeping_status,
            ])->values()->all(),
            'active_units' => $r->units->where('is_active', true)->count(),
            'products' => $r->products->map(fn (Product $p) => ProductResource::shape($p))->values()->all(),
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
