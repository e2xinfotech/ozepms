<?php

namespace App\Http\Controllers\Web;

use App\Domain\Auth\TwoFactorService;
use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function security(Request $request, TwoFactorService $twoFactor): View
    {
        $user = $request->user();

        return Page::render('account/security', [
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'two_factor_required' => $twoFactor->isRequiredFor($user),
            'recovery_codes_left' => count($user->two_factor_recovery ?? []),
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'recent_logins' => \App\Models\LoginAttempt::query()
                ->where('user_id', $user->id)->latest('id')->limit(8)
                ->get(['ip', 'user_agent', 'succeeded', 'reason', 'created_at'])
                ->map(fn ($a) => [
                    'ip' => $a->ip, 'agent' => $a->user_agent, 'ok' => $a->succeeded,
                    'reason' => $a->reason, 'at' => $a->created_at?->toIso8601String(),
                ]),
            'password_rules' => ['min' => config('ozepms.security.password_min_length')],
        ], __('auth.security_title'));
    }

    public function profile(Request $request): View
    {
        $user = $request->user();

        return Page::render('account/profile', [
            'profile' => $user->only(['name', 'email', 'job_title', 'phone_e164', 'locale']),
        ], __('auth.profile_title'));
    }
}
