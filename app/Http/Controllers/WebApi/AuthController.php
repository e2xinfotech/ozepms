<?php

namespace App\Http\Controllers\WebApi;

use App\Domain\Auth\LoginResult;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\PasswordService;
use App\Domain\Auth\TwoFactorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginService $login): JsonResponse
    {
        $result = $login->attempt(
            $request,
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (bool) $request->validated('remember', false),
        );

        return match ($result) {
            LoginResult::Success => response()->json(['redirect' => $this->intended($request)]),
            LoginResult::TwoFactorRequired => response()->json(['redirect' => route('two-factor.challenge'), 'two_factor' => true]),
            LoginResult::Throttled => $this->error(429, 'THROTTLED', __('auth.throttled')),
            // A locked account answers like a throttled sign-in, so a stranger cannot tell which e-mails have accounts.
            LoginResult::Locked => $this->error(429, 'THROTTLED', __('auth.throttled')),
            LoginResult::Disabled => $this->error(403, 'ACCOUNT_DISABLED', __('auth.disabled')),
            // Unknown e-mail and wrong password share one answer so accounts cannot be discovered.
            LoginResult::InvalidCredentials => $this->error(422, 'INVALID_CREDENTIALS', __('auth.invalid')),
        };
    }

    public function twoFactor(TwoFactorChallengeRequest $request, LoginService $login, TwoFactorService $twoFactor): JsonResponse
    {
        $session = $request->session();
        $pending = $session->get(LoginService::SESSION_PENDING_2FA);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            $session->forget(LoginService::SESSION_PENDING_2FA);

            return $this->error(419, 'TWO_FACTOR_EXPIRED', __('auth.two_factor_expired'), ['redirect' => route('login')]);
        }

        $user = User::query()->find($pending['user_id']);
        if (! $user || ! $user->isActive()) {
            $session->forget(LoginService::SESSION_PENDING_2FA);

            return $this->error(419, 'TWO_FACTOR_EXPIRED', __('auth.two_factor_expired'), ['redirect' => route('login')]);
        }

        // Wrong codes are counted per person across sign-ins, so starting over does not give fresh guesses.
        $limiterKey = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 10)) {
            $session->forget(LoginService::SESSION_PENDING_2FA);

            return $this->error(429, 'THROTTLED', __('auth.throttled'), ['redirect' => route('login')]);
        }

        if (! $twoFactor->verify($user, (string) $request->validated('code'))) {
            RateLimiter::hit($limiterKey, 900);
            $attempts = (int) ($pending['attempts'] ?? 0) + 1;
            Log::channel('security')->notice('Two-factor code rejected', ['user_id' => $user->id, 'attempts' => $attempts]);

            if ($attempts >= (int) config('ozepms.security.two_factor_max_attempts')) {
                $session->forget(LoginService::SESSION_PENDING_2FA);

                return $this->error(419, 'TWO_FACTOR_EXPIRED', __('auth.two_factor_expired'), ['redirect' => route('login')]);
            }
            $session->put(LoginService::SESSION_PENDING_2FA, ['attempts' => $attempts] + $pending);

            return $this->error(422, 'VALIDATION_FAILED', __('errors.validation'), ['fields' => ['code' => [__('auth.two_factor_invalid')]]]);
        }

        RateLimiter::clear($limiterKey);
        $login->completeLogin($request, $user, (bool) ($pending['remember'] ?? false));

        return response()->json(['redirect' => $this->intended($request)]);
    }

    public function forgotPassword(ForgotPasswordRequest $request, PasswordService $passwords): JsonResponse
    {
        $passwords->sendResetLink((string) $request->validated('email'));

        // Always the same answer, whether or not the address has an account.
        return response()->json(['message' => __('auth.link_sent')]);
    }

    public function resetPassword(ResetPasswordRequest $request, PasswordService $passwords): JsonResponse
    {
        $passwords->reset($request->only(['email', 'token']) + ['password' => (string) $request->validated('password')]);

        $request->session()->flash('success', __('auth.reset_done'));

        return response()->json(['message' => __('auth.reset_done'), 'redirect' => route('login')]);
    }

    public function logout(Request $request, LoginService $login, \App\Domain\Platform\ImpersonationService $impersonation): JsonResponse
    {
        // Signing out while acting as someone only returns to the real account.
        if ($impersonation->state($request) !== null) {
            $actor = $impersonation->stop($request);

            return response()->json(['redirect' => $actor ? route('admin.dashboard') : route('login')]);
        }

        $login->logout($request);

        return response()->json(['redirect' => route('login')]);
    }

    private function intended(Request $request): string
    {
        $url = (string) $request->session()->pull('url.intended', route('home'));

        // Only same-site paths; JSON endpoints are never a landing page.
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $sameHost = parse_url($url, PHP_URL_HOST) === null || parse_url($url, PHP_URL_HOST) === $request->getHost();

        return $sameHost && ! str_starts_with($path, '/web-api') ? $url : route('home');
    }

    /** @param  array<string, mixed>  $extra */
    private function error(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        $fields = $extra['fields'] ?? null;
        unset($extra['fields']);

        return response()->json(['error' => array_filter([
            'code' => $code,
            'message' => $message,
            'fields' => $fields,
        ]) + $extra], $status);
    }
}
