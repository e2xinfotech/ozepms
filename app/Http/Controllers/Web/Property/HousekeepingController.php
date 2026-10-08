<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Housekeeping\HousekeepingPresenter;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Property\Concerns\ChecksPermissions;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HousekeepingController extends Controller
{
    use ChecksPermissions;

    public function index(Request $request, HousekeepingPresenter $presenter): View
    {
        return Page::render('property/housekeeping/index', [
            'data' => $presenter->page(),
            'filters' => $request->only(['tab']),
            'can' => $this->can(['manage' => 'housekeeping.manage', 'update' => 'housekeeping.update']),
        ], __('nav.housekeeping'));
    }
}
