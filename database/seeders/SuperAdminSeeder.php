<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first platform accounts from .env values: the Super Admin (OZ_SUPER_ADMIN_*) and one
 * Admin (OZ_ADMIN_*). If no password is given, a strong one is generated and printed once.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $this->account('super_admin', 'OZ_SUPER_ADMIN', 'superadmin@e2xinfotech.in', 'E2X Super Admin', 'Super Administrator');
        $this->account('admin', 'OZ_ADMIN', 'admin@e2xinfotech.in', 'E2X Admin', 'Administrator');
    }

    private function account(string $roleCode, string $prefix, string $defaultEmail, string $defaultName, string $title): void
    {
        $email = Str::lower((string) env($prefix.'_EMAIL', $defaultEmail));
        $label = $roleCode === 'super_admin' ? 'Super Admin' : 'Admin';

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info("{$label} {$email} already exists.");

            return;
        }

        $password = (string) env($prefix.'_PASSWORD') ?: Str::password(16);

        $user = User::query()->create([
            'name' => env($prefix.'_NAME', $defaultName),
            'job_title' => $title,
            'email' => $email,
            'password' => $password,
            'status' => 'active',
            'is_platform_user' => true,
            'locale' => 'en',
        ]);
        $user->forceFill(['email_verified_at' => now(), 'password_changed_at' => now()])->save();

        $role = Role::query()->whereNull('property_id')->where('code', $roleCode)->firstOrFail();
        $user->platformRoles()->sync([$role->id]);

        $this->command?->info("{$label} created: {$email}");
        if (! env($prefix.'_PASSWORD')) {
            $this->command?->warn("Generated password (shown once): {$password}");
        }
    }
}
