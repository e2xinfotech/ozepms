<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\Queries\AuditQuery;
use App\Http\Controllers\Controller;
use App\Support\Listing;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /** Platform-wide audit trail filtered by ?action=&user=&property=&from=&to=. */
    public function index(Request $request, AuditQuery $audit): View
    {
        $labels = trans('admin.actions');

        return Page::render('admin/audit/index', $audit->list($request) + [
            'filters' => Listing::filters($request, ['action', 'user', 'property', 'from', 'to']),
            'actions' => collect(is_array($labels) ? $labels : [])
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values()->all(),
        ], __('admin.audit_title'));
    }
}
