<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;

class SystemController extends Controller
{
    public function index(): View
    {
        return Page::render('admin/system/index');
    }

}
