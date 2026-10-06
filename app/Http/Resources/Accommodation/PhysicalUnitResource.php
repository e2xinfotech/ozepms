<?php

namespace App\Http\Resources\Accommodation;

use App\Domain\Accommodation\Queries\PhysicalUnitQuery;
use App\Domain\Accommodation\Queries\HistoryQuery;
use App\Models\PhysicalUnit;
use App\Models\UnitBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A PMS room for the side panel: list row + room type details, blocks, amenities, images and history.
 * The resource expects a unit loaded through PhysicalUnitQuery::find() (it carries the live status).
 *
 * @mixin PhysicalUnit
 */
class PhysicalUnitResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var PhysicalUnit $u */
        $u = $this->resource;
        $roomType = $u->roomType()->with(['amenities', 'images', 'products' => fn ($q) => $q->where('is_active', true)->where('is_default', true)->with('ratePlan')])->first();
        $default = $roomType?->products->first();
        $today = app(PhysicalUnitQuery::class)->today();

        $blocks = UnitBlock::query()->where('unit_id', $u->id)->whereNull('released_at')->where('end_date', '>', $today)
            ->orderBy('start_date')->get();

        return app(PhysicalUnitQuery::class)->row($u) + [
            'notes' => $u->notes,
            'sort_order' => $u->sort_order,
            'max_adults' => $roomType?->max_adults,
            'max_occupancy' => $roomType?->max_occupancy,
            'extra_bed_allowed' => (bool) $roomType?->extra_bed_allowed,
            'default_rate_plan' => $default?->ratePlan ? $default->ratePlan->name.' ('.$default->ratePlan->code.')' : null,
            'next_cleaning_at' => $u->last_cleaned_at?->addDay()->toIso8601String(),
            'in_maintenance' => $blocks->contains(fn (UnitBlock $b) => $b->start_date->toDateString() <= $today),
            'blocks' => $blocks->map(fn (UnitBlock $b) => [
                'id' => $b->id, 'type' => $b->block_type, 'start_date' => $b->start_date->toDateString(),
                'end_date' => $b->end_date->toDateString(), 'reason' => $b->reason,
            ])->values()->all(),
            'amenities' => app(\App\Domain\Accommodation\UnitAmenityService::class)->effective($u),
            'images' => $roomType ? $roomType->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url(), 'alt' => $i->alt_text])->values()->all() : [],
            'history' => app(HistoryQuery::class)->for($u),
        ];
    }
}
