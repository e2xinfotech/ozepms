<?php

namespace App\Http\Controllers\Web\Property;

use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return Page::render('property/dashboard/index');
    }

}
