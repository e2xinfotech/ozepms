<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserResetPasswordTest extends TestCase
{
    public function test_sets_a_policy_compliant_password_and_signs_the_user_out(): void
    {
        [, $owner] = $this->createPropertyWithOwner();

        $this->artisan('user:reset-password', ['email' => strtoupper($owner->email), '--password' => 'short'])->assertFailed();
        $this->artisan('user:reset-password', ['email' => 'nobody@example.com'])->assertFailed();
        $this->artisan('user:reset-password', ['email' => $owner->email, '--password' => 'Verify#Strong2026'])->assertSuccessful();

        $this->assertTrue(Hash::check('Verify#Strong2026', $owner->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset_console']);
        $this->artisan('user:reset-password', ['email' => $owner->email])->expectsOutputToContain('shown once')->assertSuccessful();
        $this->assertFalse(Hash::check('Verify#Strong2026', $owner->fresh()->password));
    }
}
