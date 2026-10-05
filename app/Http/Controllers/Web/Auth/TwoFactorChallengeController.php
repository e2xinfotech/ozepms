<?php

namespace App\Http\Controllers\Web\Auth;

use App\Domain\Auth\LoginService;
use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TwoFactorChallengeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $pending = $request->session()->get(LoginService::SESSION_PENDING_2FA);
        if (! $pending || $pending['expires_at'] < now()->timestamp) {
            return redirect()->route('login');
        }

        return Page::render('auth/two-factor', [], __('auth.two_factor_title'), 'auth');
    }
}
