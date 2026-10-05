<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Starts enrolment: stores an unconfirmed secret and returns the setup URI. */
    public function begin(User $user): array
    {
        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'uri' => Totp::uri($secret, $user->email, config('ozepms.brand.name')),
        ];
    }

    /** Confirms enrolment with a code from the app; returns one-time recovery codes. */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || ! Totp::verify($user->two_factor_secret, $code)) {
            return null;
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery' => array_map(fn ($c) => hash('sha256', $c), $codes),
        ])->save();

        $this->audit->log('user.two_factor_enabled', $user);

        return $codes;
    }

    public function verify(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }
        if (Totp::verify($user->two_factor_secret, $code)) {
            return true;
        }

        return $this->useRecoveryCode($user, $code);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->log('user.two_factor_disabled', $user);
    }

    /**
     * Turns two-step verification off after re-checking the password.
     *
     * @throws ValidationException when the password is wrong or 2FA is mandatory for the user
     */
    public function turnOff(User $user, string $currentPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.password')]);
        }
        if ($this->isRequiredFor($user)) {
            throw ValidationException::withMessages(['current_password' => __('auth.two_factor_mandatory_off')]);
        }

        $this->disable($user);
    }

    public function isRequiredFor(User $user): bool
    {
        $rules = config('ozepms.security.require_2fa');

        if ($user->is_platform_user && $rules['platform_users']) {
            return true;
        }

        return $rules['property_owners']
            && $user->memberships()->withoutGlobalScope('property')->where('is_owner', true)->exists();
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $hash = hash('sha256', strtoupper(trim($code)));
        $remaining = $user->two_factor_recovery ?? [];
        $index = array_search($hash, $remaining, true);
        if ($index === false) {
            return false;
        }
        unset($remaining[$index]);
        $user->forceFill(['two_factor_recovery' => array_values($remaining)])->save();
        $this->audit->log('user.recovery_code_used', $user);

        return true;
    }

    private function newRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
