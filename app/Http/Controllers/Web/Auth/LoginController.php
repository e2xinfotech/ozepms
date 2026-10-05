<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return Page::render('auth/login', [], __('auth.sign_in'), 'auth');
    }
}
