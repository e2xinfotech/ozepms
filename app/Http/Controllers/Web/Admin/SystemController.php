<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Platform\PlatformStatsService;
use App\Domain\Platform\SystemHealthService;
use App\Http\Controllers\Controller;
use App\Support\Listing;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    /** Grouped application errors (?status=open|resolved|all, ?level=, ?source=, ?q=). */
    public function index(Request $request, SystemHealthService $health, PlatformStatsService $stats, \App\Domain\Access\AccessService $access, \App\Domain\Mail\MailSettings $mail): View
    {
        return Page::render('admin/system/index', $health->list($request) + [
            'filters' => Listing::filters($request, ['status', 'level', 'source', 'q']),
            'overview' => $stats->systemOverview(),
            'email' => $access->allows($request->user(), 'platform.settings.manage') ? $mail->view(null) : null,
        ], __('admin.system_title'));
    }
}
