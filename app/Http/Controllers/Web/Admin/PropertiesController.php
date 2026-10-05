<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;

class PropertiesController extends Controller
{
    public function index(): View
    {
        return Page::render('admin/properties/index');
    }

    public function create(): View
    {
        return Page::render('admin/properties/create');
    }

    public function edit(): View
    {
        return Page::render('admin/properties/edit');
    }

}
