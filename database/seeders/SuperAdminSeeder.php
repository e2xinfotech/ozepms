<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first E2X Super Admin from OZ_ADMIN_* values in .env.
 * If no password is given, a strong one is generated and printed once.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = Str::lower((string) env('OZ_ADMIN_EMAIL', 'admin@e2xinfotech.in'));

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info("Super Admin {$email} already exists.");

            return;
        }

        $password = (string) env('OZ_ADMIN_PASSWORD') ?: Str::password(16);

        $user = User::query()->create([
            'name' => env('OZ_ADMIN_NAME', 'E2X Super Admin'),
            'job_title' => 'Super Administrator',
            'email' => $email,
            'password' => $password,
            'status' => 'active',
            'is_platform_user' => true,
            'locale' => 'en',
        ]);
        $user->forceFill(['email_verified_at' => now(), 'password_changed_at' => now()])->save();

        $role = Role::query()->whereNull('property_id')->where('code', 'super_admin')->firstOrFail();
        $user->platformRoles()->sync([$role->id]);

        $this->command?->info("Super Admin created: {$email}");
        if (! env('OZ_ADMIN_PASSWORD')) {
            $this->command?->warn("Generated password (shown once): {$password}");
        }
    }
}
