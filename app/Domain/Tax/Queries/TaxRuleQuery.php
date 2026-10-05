<?php

namespace App\Domain\Tax\Queries;

use App\Models\TaxRule;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Taxes & Fees list of the current property (own rules only; country templates are copied in). */
class TaxRuleQuery
{
    public const TABS = ['all', 'tax', 'service_charge', 'fee'];

    public const SORTS = ['name' => 'name', 'code' => 'code', 'rate' => 'rate', 'status' => 'is_active', 'priority' => 'priority'];

    /** @return array{rows: array, meta: array, counts: array} */
    public function list(Request $request): array
    {
        $filters = Listing::filters($request, ['q', 'status', 'apply_to', 'tab']);
        $tab = in_array($filters['tab'], self::TABS, true) ? $filters['tab'] : 'all';

        $filtered = TaxRule::query()->forProperty()
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when(in_array($filters['apply_to'], TaxRule::APPLY_TO, true), fn (Builder $q) => $q->whereRaw('FIND_IN_SET(?, apply_to) > 0', [$filters['apply_to']]));

        $byKind = (clone $filtered)->toBase()->selectRaw('kind, COUNT(*) AS total')->groupBy('kind')->pluck('total', 'kind');

        $rows = (clone $filtered)->with(['scopes', 'category'])
            ->when($tab !== 'all', fn (Builder $q) => $q->where('kind', $tab));
        Listing::sort($rows, $request, self::SORTS, 'priority');
        $rows->orderBy('kind')->orderBy('slab_min')->orderBy('id');

        return Listing::paginate($rows, $request, fn (TaxRule $r) => $this->row($r)) + [
            'counts' => [
                'all' => (int) $byKind->sum(),
                'tax' => (int) ($byKind['tax'] ?? 0),
                'service_charge' => (int) ($byKind['service_charge'] ?? 0),
                'fee' => (int) ($byKind['fee'] ?? 0),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function row(TaxRule $r): array
    {
        return [
            'id' => $r->public_id,
            'name' => $r->name,
            'code' => $r->code,
            'kind' => $r->kind,
            'tax_type' => $r->tax_type,
            'calc_type' => $r->calc_type,
            'rate' => $r->rate,
            'apply_to' => $r->applyToList(),
            'slab_min' => $r->slab_min,
            'slab_max' => $r->slab_max,
            'component_mode' => $r->component_mode,
            'sac' => $r->category?->default_sac_hsn,
            'is_inclusive' => $r->is_inclusive,
            'is_active' => $r->is_active,
            'is_default_for_new_room_types' => $r->is_default_for_new_room_types,
            'scoped' => $r->scopes->isNotEmpty(),
        ];
    }

    public function hasOwnRules(): bool
    {
        return TaxRule::query()->forProperty()->exists();
    }
}
