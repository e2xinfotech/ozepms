<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return Page::render('auth/forgot-password', [], __('auth.forgot_title'), 'auth');
    }

    public function reset(Request $request, string $token): View
    {
        return Page::render('auth/reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'password_rules' => ['min' => config('ozepms.security.password_min_length')],
        ], __('auth.reset_title'), 'auth');
    }
}
