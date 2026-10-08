<?php

namespace App\Domain\Billing\Queries;

use App\Models\Service;
use App\Support\Listing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Services list (server-side search, status tab, pagination) for the setup page. */
class ServiceQuery
{
    /** @return array<string, mixed> rows, meta, counts, filters */
    public function page(Request $request): array
    {
        $filters = Listing::filters($request, ['q', 'status', 'posting_rule']);
        $query = Service::query()->with('taxCategory:id,code,name');
        if ($filters['q'] !== '') {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }
        if (in_array($filters['posting_rule'], Service::POSTING_RULES, true)) {
            $query->where('posting_rule', $filters['posting_rule']);
        }
        $counts = (clone $query)->toBase()->reorder()
            ->selectRaw('COUNT(*) AS all_count, COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS inactive')->first();
        if ($filters['status'] === 'active' || $filters['status'] === 'inactive') {
            $query->where('is_active', $filters['status'] === 'active');
        }
        Listing::sort($query, $request, ['name' => 'name', 'code' => 'code', 'price' => 'price'], 'sort_order');
        $query->orderBy('name');

        // Times posted (index fk_fl_service), only for the rows of the page.
        $query->select('services.*')->selectSub(DB::table('folio_lines')->selectRaw('COUNT(*)')
            ->whereColumn('folio_lines.service_id', 'services.id')->where('is_void', 0)->whereNull('void_of_line_id'), 'times_posted');
        $page = Listing::paginate($query, $request, fn (Service $s) => $this->row($s) + ['times_posted' => (int) $s->getAttribute('times_posted')]);

        return $page + [
            'counts' => ['all' => (int) $counts->all_count, 'active' => (int) $counts->active, 'inactive' => (int) $counts->inactive],
            'filters' => $filters,
        ];
    }

    /** @return array<string, mixed> */
    public function row(Service $s): array
    {
        return [
            'id' => $s->public_id,
            'code' => $s->code,
            'name' => $s->name,
            'description' => $s->description,
            'price' => (string) $s->price,
            'tax_category' => $s->taxCategory?->code,
            'sac_hsn_code' => $s->sac_hsn_code,
            'posting_rule' => $s->posting_rule,
            'department' => $s->department,
            'is_active' => $s->is_active,
            'sort_order' => (int) $s->sort_order,
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Service $s): array
    {
        $s->loadMissing('taxCategory:id,code,name');

        return $this->row($s) + [
            'times_posted' => (int) DB::table('folio_lines')->where('service_id', $s->id)->where('is_void', 0)->whereNull('void_of_line_id')->count(),
        ];
    }
}
