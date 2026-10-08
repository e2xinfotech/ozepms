<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Super Admins can only be created here, from the server console. An existing account is promoted
 * (its password is kept). When no password is given for a new account, a strong one is generated
 * and shown once.
 */
class UserCreateSuperAdmin extends Command
{
    protected $signature = 'user:create-super-admin
        {email=superadmin@e2xinfotech.in : E-mail of the Super Admin}
        {--name=E2X Super Admin : Display name}
        {--password= : Password (generated when left out)}';

    protected $description = 'Create a Super Admin (or promote an existing account) from the server console';

    public function handle(AuditLogger $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $check = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:190']]);
        if ($check->fails()) {
            $this->error($check->errors()->first('email'));

            return self::FAILURE;
        }

        $role = Role::query()->whereNull('property_id')->where('scope', 'platform')->where('code', 'super_admin')->first();
        if ($role === null) {
            $this->error('Roles are missing. Run: php artisan db:seed --class=PermissionSeeder --force');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $generated = null;

        if ($user === null) {
            $given = (string) $this->option('password');
            $password = $given !== '' ? $given : Str::password(16);
            $check = Validator::make(['password' => $password], ['password' => ['required', 'string', 'max:200', Password::defaults()]]);
            if ($check->fails()) {
                $this->error($check->errors()->first('password'));

                return self::FAILURE;
            }
            $user = User::query()->create([
                'name' => (string) $this->option('name'),
                'job_title' => 'Super Administrator',
                'email' => $email,
                'password' => $password,
                'status' => 'active',
                'is_platform_user' => true,
                'locale' => 'en',
            ]);
            $user->forceFill(['email_verified_at' => now(), 'password_changed_at' => now()])->save();
            $generated = $given === '' ? $password : null;
        }

        $user->forceFill(['is_platform_user' => true, 'status' => 'active'])->save();
        $user->platformRoles()->sync([$role->id]);
        $audit->log('user.super_admin_created_console', $user, ['after' => ['email' => $email]], null, null);

        $this->info("Super Admin ready: {$email}");
        if ($generated !== null) {
            $this->warn("Password (shown once): {$generated}");
        }

        return self::SUCCESS;
    }
}
