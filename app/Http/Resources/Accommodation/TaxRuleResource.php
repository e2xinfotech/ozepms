<?php

namespace App\Http\Resources\Accommodation;

use App\Domain\Tax\Queries\TaxRuleQuery;
use App\Models\TaxRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxRule */
class TaxRuleResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var TaxRule $r */
        $r = $this->resource;
        $r->loadMissing(['scopes.roomType', 'scopes.ratePlan', 'category']);

        return app(TaxRuleQuery::class)->row($r) + [
            'description' => $r->description,
            'slab_basis' => $r->slab_basis,
            'is_compound' => $r->is_compound,
            'include_in_displayed_rate' => $r->include_in_displayed_rate,
            'priority' => $r->priority,
            'effective_from' => $r->effective_from?->toDateString(),
            'effective_to' => $r->effective_to?->toDateString(),
            'room_types' => $r->scopes->map(fn ($s) => $s->roomType?->public_id)->filter()->unique()->values()->all(),
            'rate_plans' => $r->scopes->map(fn ($s) => $s->ratePlan?->public_id)->filter()->unique()->values()->all(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
