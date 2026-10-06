<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Server-side password reset (e.g. the Super Admin's first password, or a lost one without e-mail).
 * The new password follows the password policy; when none is given a strong one is generated and
 * shown once. Signs the user out everywhere.
 */
class UserResetPassword extends Command
{
    protected $signature = 'user:reset-password {email : E-mail of the user} {--password= : New password (generated when left out)}';

    protected $description = 'Set a new password for a user from the server console';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', Str::lower((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No user with this e-mail.');

            return self::FAILURE;
        }
        $given = (string) $this->option('password');
        $password = $given !== '' ? $given : Str::password(16);
        $check = Validator::make(['password' => $password], ['password' => ['required', 'string', 'max:200', Password::defaults()]]);
        if ($check->fails()) {
            $this->error($check->errors()->first('password'));

            return self::FAILURE;
        }

        $user->forceFill(['password' => $password, 'password_changed_at' => now(), 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $audit->log('user.password_reset_console', $user, [], null, null);

        $this->info("Password updated for {$user->email}.");
        if ($given === '') {
            $this->warn("New password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
