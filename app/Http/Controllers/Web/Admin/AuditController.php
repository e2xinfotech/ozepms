<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;

class AuditController extends Controller
{
    public function index(): View
    {
        return Page::render('admin/audit/index');
    }

}
