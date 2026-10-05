<?php

namespace App\Http\Controllers\Web\Property;

use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;

class PropertiesController extends Controller
{
    public function index(): View
    {
        return Page::render('property/properties/index');
    }

}
