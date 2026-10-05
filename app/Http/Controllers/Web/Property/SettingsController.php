<?php

namespace App\Http\Controllers\Web\Property;

use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return Page::render('property/settings/index');
    }

}
