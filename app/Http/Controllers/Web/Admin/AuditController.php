<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\Queries\AuditQuery;
use App\Domain\Audit\Queries\RequestTrailQuery;
use App\Http\Controllers\Controller;
use App\Support\Listing;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /**
     * Platform-wide audit trail filtered by ?action=&user=&via=&property=&from=&to=.
     * ?view=requests shows every data-changing request instead (field names only).
     */
    public function index(Request $request, AuditQuery $audit, RequestTrailQuery $trail): View
    {
        $labels = trans('admin.actions');
        $requests = $request->query('view') === 'requests';

        return Page::render('admin/audit/index', ($requests ? $trail->list($request) : $audit->list($request)) + [
            'view' => $requests ? 'requests' : 'changes',
            'filters' => Listing::filters($request, $requests ? RequestTrailQuery::FILTERS : ['action', 'user', 'property', 'from', 'to', 'via']),
            'actions' => collect(is_array($labels) ? $labels : [])
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values()->all(),
        ], __('admin.audit_title'));
    }
}
