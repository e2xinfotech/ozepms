<?php

namespace App\Http\Controllers\WebApi;

use App\Domain\Auth\PasswordService;
use App\Domain\Auth\TwoFactorService;
use App\Domain\Users\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\CurrentPasswordRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The signed-in user's own account: two-step verification, password, profile. */
class AccountController extends Controller
{
    public function beginTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        if ($request->user()->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => __('auth.two_factor_on')]);
        }

        return response()->json($twoFactor->begin($request->user()));
    }

    public function confirmTwoFactor(ConfirmTwoFactorRequest $request, TwoFactorService $twoFactor): JsonResponse
    {
        $codes = $twoFactor->confirm($request->user(), (string) $request->validated('code'));
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => __('auth.two_factor_invalid')]);
        }

        return response()->json(['recovery_codes' => $codes, 'message' => __('auth.two_factor_enabled')]);
    }

    public function disableTwoFactor(CurrentPasswordRequest $request, TwoFactorService $twoFactor): JsonResponse
    {
        $twoFactor->turnOff($request->user(), (string) $request->validated('current_password'));

        return response()->json(['message' => __('auth.two_factor_disabled')]);
    }

    public function updatePassword(UpdatePasswordRequest $request, PasswordService $passwords): JsonResponse
    {
        $passwords->change(
            $request->user(),
            (string) $request->validated('current_password'),
            (string) $request->validated('password'),
            $request->session()->getId(),
        );

        // Keep this browser signed in; other sessions lose their remember-me cookie.
        $request->session()->regenerate();

        return response()->json(['message' => __('auth.password_updated')]);
    }

    public function updateProfile(UpdateProfileRequest $request, UserService $users): JsonResponse
    {
        $user = $users->updateProfile($request->user(), $request->validated());
        $request->session()->put('locale', $user->locale);

        return response()->json([
            'message' => __('ui.saved'),
            'profile' => $user->only(['name', 'email', 'job_title', 'phone_e164', 'locale']),
        ]);
    }
}
