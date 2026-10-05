<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Password login with throttling, progressive lockout and two-factor hand-off.
 * The same generic message is returned for unknown e-mail and wrong password.
 */
class LoginService
{
    public const SESSION_PENDING_2FA = 'auth.pending_2fa';

    public function __construct(private readonly AuditLogger $audit) {}

    public function attempt(Request $request, string $email, string $password, bool $remember): LoginResult
    {
        $email = Str::lower(trim($email));
        $throttleKey = 'login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, config('ozepms.security.login_attempts_per_minute'))) {
            $this->record($request, $email, null, false, 'throttled');

            return LoginResult::Throttled;
        }

        $user = User::query()->where('email', $email)->first();

        // Hash something even when the user does not exist so timing does not reveal accounts.
        $valid = $user ? Hash::check($password, $user->password) : Hash::check($password, $this->dummyHash());

        if ($user && $user->isLocked()) {
            $this->record($request, $email, $user, false, 'locked');

            return LoginResult::Locked;
        }

        if (! $user || ! $valid) {
            RateLimiter::hit($throttleKey, 60);
            if ($user) {
                $this->registerFailure($user);
            }
            $this->record($request, $email, $user, false, 'bad_credentials');

            return LoginResult::InvalidCredentials;
        }

        if (! $user->isActive()) {
            $this->record($request, $email, $user, false, 'disabled');

            return LoginResult::Disabled;
        }

        RateLimiter::clear($throttleKey);

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(self::SESSION_PENDING_2FA, [
                'user_id' => $user->id,
                'remember' => $remember,
                'expires_at' => now()->addMinutes(5)->timestamp,
            ]);
            $this->record($request, $email, $user, true, 'password_ok_2fa_pending');

            return LoginResult::TwoFactorRequired;
        }

        $this->completeLogin($request, $user, $remember);

        return LoginResult::Success;
    }

    public function completeLogin(Request $request, User $user, bool $remember): void
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget(self::SESSION_PENDING_2FA);

        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'status' => $user->status === 'invited' ? 'active' : $user->status,
        ])->save();

        $this->record($request, $user->email, $user, true, null);
        $this->audit->log('auth.login', $user, userId: $user->id);
    }

    public function logout(Request $request): void
    {
        if ($user = $request->user()) {
            $this->audit->log('auth.logout', $user, userId: $user->id);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function dummyHash(): string
    {
        return \Illuminate\Support\Facades\Cache::rememberForever('auth.dummy_hash', fn () => Hash::make(Str::random(40)));
    }

    private function registerFailure(User $user): void
    {
        $failures = $user->failed_login_count + 1;
        $data = ['failed_login_count' => $failures];

        if ($failures >= config('ozepms.security.lockout_after_failures')) {
            $data['locked_until'] = now()->addMinutes(config('ozepms.security.lockout_minutes'));
            $data['failed_login_count'] = 0;
            Log::channel('security')->warning('Account locked after repeated failures', ['user_id' => $user->id]);
            $this->audit->log('auth.locked', $user, userId: $user->id);
        }

        $user->forceFill($data)->save();
    }

    private function record(Request $request, string $email, ?User $user, bool $ok, ?string $reason): void
    {
        LoginAttempt::query()->create([
            'user_id' => $user?->id,
            'email' => mb_substr($email, 0, 190),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'succeeded' => $ok,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        if (! $ok) {
            Log::channel('security')->notice('Login failed', ['email' => $email, 'reason' => $reason]);
        }
    }
}
