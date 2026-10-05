<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password links, resets and changes. Responses never reveal whether an
 * e-mail address belongs to an account.
 */
class PasswordService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function sendResetLink(string $email): void
    {
        $email = Str::lower(trim($email));
        $user = User::query()->where('email', $email)->first();

        // Disabled accounts get no link, but the caller sees the same answer.
        if (! $user || $user->status === 'disabled') {
            Log::channel('security')->notice('Password link requested for unknown or disabled account', ['email' => $email]);

            return;
        }

        $status = Password::broker()->sendResetLink(['email' => $email]);
        if ($status === Password::RESET_LINK_SENT) {
            $this->audit->log('user.password_link_sent', $user, userId: $user->id);
        }
    }

    /**
     * @param  array{email: string, token: string, password: string}  $data
     *
     * @throws ValidationException when the token is invalid or expired
     */
    public function reset(array $data): User
    {
        $resetUser = null;

        $status = Password::broker()->reset(
            [
                'email' => Str::lower(trim($data['email'])),
                'token' => $data['token'],
                'password' => $data['password'],
                'password_confirmation' => $data['password'],
            ],
            function (User $user, string $password) use (&$resetUser) {
                if ($user->status === 'disabled') {
                    return;
                }
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                    'failed_login_count' => 0,
                    'locked_until' => null,
                    // An invited user who sets a password has accepted the invitation.
                    'status' => $user->status === 'invited' ? 'active' : $user->status,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                event(new PasswordReset($user));
                $this->audit->log('user.password_reset', $user, userId: $user->id);
                $resetUser = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $resetUser) {
            throw ValidationException::withMessages(['email' => __('auth.reset_invalid')]);
        }

        return $resetUser;
    }

    /** @throws ValidationException when the current password is wrong */
    public function change(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.password')]);
        }

        $user->forceFill([
            'password' => $new,
            'password_changed_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        $this->audit->log('user.password_changed', $user);
    }
}
