<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\LoginService;
use App\Domain\Auth\Totp;
use Tests\TestCase;

class LoginTest extends TestCase
{
    private function login(string $email, string $password = 'Secret-Pass-123')
    {
        return $this->postJson('/web-api/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_valid_credentials_sign_the_user_in(): void
    {
        $user = $this->makeUser();

        $this->login($user->email)->assertOk()->assertJsonStructure(['redirect']);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertDatabaseHas('login_attempts', ['user_id' => $user->id, 'succeeded' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_email_is_case_insensitive(): void
    {
        $user = $this->makeUser(['email' => 'mixed@example.test']);

        $this->login('  MIXED@example.test ')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_answer(): void
    {
        $user = $this->makeUser();

        $wrong = $this->login($user->email, 'not-the-password')->assertStatus(422)->json('error');
        $unknown = $this->login('nobody@example.test')->assertStatus(422)->json('error');

        $this->assertSame('INVALID_CREDENTIALS', $wrong['code']);
        $this->assertSame($wrong['code'], $unknown['code']);
        $this->assertSame($wrong['message'], $unknown['message']);
        $this->assertGuest();
    }

    public function test_missing_fields_are_validation_errors(): void
    {
        $this->postJson('/web-api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['email', 'password']]]);
    }

    public function test_disabled_account_cannot_sign_in(): void
    {
        $user = $this->makeUser(['status' => 'disabled']);

        $this->login($user->email)->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
        $this->assertGuest();
    }

    public function test_account_is_locked_after_repeated_failures(): void
    {
        config([
            'ozepms.security.lockout_after_failures' => 3,
            'ozepms.security.login_attempts_per_minute' => 100,
            'ozepms.security.auth_requests_per_minute' => 100,
        ]);
        $user = $this->makeUser();

        foreach (range(1, 3) as $_) {
            $this->login($user->email, 'wrong-password')->assertStatus(422);
        }

        // Even the right password is refused while the lock lasts.
        $this->login($user->email)->assertStatus(423)->assertJsonPath('error.code', 'ACCOUNT_LOCKED');
        $this->assertNotNull($user->refresh()->locked_until);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.locked', 'user_id' => $user->id]);
        $this->assertGuest();

        $this->travel(config('ozepms.security.lockout_minutes') + 1)->minutes();
        $this->login($user->email)->assertOk();
        $this->assertSame(0, $user->refresh()->failed_login_count);
    }

    public function test_failed_attempts_are_throttled_per_email_and_ip(): void
    {
        config(['ozepms.security.login_attempts_per_minute' => 3, 'ozepms.security.lockout_after_failures' => 100]);
        $user = $this->makeUser();

        foreach (range(1, 3) as $_) {
            $this->login($user->email, 'wrong-password')->assertStatus(422);
        }

        $this->login($user->email)->assertStatus(429)->assertJsonPath('error.code', 'THROTTLED');
        $this->assertGuest();
    }

    public function test_auth_endpoints_are_rate_limited_per_ip(): void
    {
        $limit = (int) config('ozepms.security.auth_requests_per_minute');

        foreach (range(1, $limit) as $i) {
            $this->postJson('/web-api/auth/forgot-password', ['email' => "user{$i}@example.test"])->assertOk();
        }

        $this->postJson('/web-api/auth/forgot-password', ['email' => 'last@example.test'])->assertStatus(429);
    }

    public function test_signed_in_user_cannot_use_guest_endpoints(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/login')->assertRedirect(route('home'));
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/web-api/auth/logout')->assertOk()->assertJsonPath('redirect', route('login'));
        $this->assertGuest();
    }

    public function test_forgot_password_answers_the_same_for_unknown_addresses(): void
    {
        $user = $this->makeUser();

        $known = $this->postJson('/web-api/auth/forgot-password', ['email' => $user->email])->assertOk()->json('message');
        $unknown = $this->postJson('/web-api/auth/forgot-password', ['email' => 'ghost@example.test'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/home')->assertRedirect(route('login'));
        $this->getJson('/web-api/lookups/states?country=IN')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /* ---------- Two-step verification ---------- */

    private function userWithTwoFactor(): array
    {
        $user = $this->makeUser();
        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery' => [hash('sha256', 'RECOVERY-0001')],
        ])->save();

        return [$user, $secret];
    }

    public function test_two_factor_users_must_enter_a_code_after_the_password(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $this->login($user->email)->assertOk()->assertJsonPath('two_factor', true)->assertJsonPath('redirect', route('two-factor.challenge'));
        $this->assertGuest();
        $this->get('/two-factor')->assertOk();

        $this->postJson('/web-api/auth/two-factor', ['code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['redirect']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_two_factor_code_is_rejected(): void
    {
        [$user] = $this->userWithTwoFactor();
        $this->login($user->email)->assertOk();

        $this->postJson('/web-api/auth/two-factor', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->assertGuest();
    }

    public function test_too_many_wrong_codes_discard_the_pending_sign_in(): void
    {
        config(['ozepms.security.two_factor_max_attempts' => 2]);
        [$user, $secret] = $this->userWithTwoFactor();
        $this->login($user->email)->assertOk();

        $this->postJson('/web-api/auth/two-factor', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/web-api/auth/two-factor', ['code' => '000001'])->assertStatus(419)->assertJsonPath('error.code', 'TWO_FACTOR_EXPIRED');

        // The pending sign-in is gone: even the right code no longer works.
        $this->postJson('/web-api/auth/two-factor', ['code' => Totp::code($secret)])->assertStatus(419);
        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->login($user->email)->assertOk();
        $this->postJson('/web-api/auth/two-factor', ['code' => 'RECOVERY-0001'])->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->postJson('/web-api/auth/logout')->assertOk();
        $this->login($user->email)->assertOk();
        $this->postJson('/web-api/auth/two-factor', ['code' => 'RECOVERY-0001'])->assertStatus(422);
    }

    public function test_two_factor_page_needs_a_pending_sign_in(): void
    {
        $this->get('/two-factor')->assertRedirect(route('login'));
    }

    public function test_pending_sign_in_expires(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $this->login($user->email)->assertOk();

        $this->travel(6)->minutes();

        $this->postJson('/web-api/auth/two-factor', ['code' => Totp::code($secret)])->assertStatus(419);
        $this->assertGuest();
        $this->assertNull(session(LoginService::SESSION_PENDING_2FA));
    }

    public function test_users_who_must_use_two_factor_are_sent_to_set_it_up(): void
    {
        config(['ozepms.security.require_2fa.platform_users' => true]);
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)->get('/admin')->assertRedirect(route('account.security'));
        $this->actingAs($admin)->get('/account/security')->assertOk();
        $this->actingAs($admin)->getJson('/web-api/lookups/states?country=IN')
            ->assertStatus(403)->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED');
    }

    public function test_property_owners_must_use_two_factor_when_configured(): void
    {
        config(['ozepms.security.require_2fa.property_owners' => true]);
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->get("/p/{$property->code}/dashboard")->assertRedirect(route('account.security'));

        $manager = $this->addMember($property, 'hotel_manager');
        $this->actingAs($manager)->get("/p/{$property->code}/dashboard")->assertOk();
    }

    public function test_disabled_user_with_a_live_session_is_signed_out(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get('/account/profile')->assertOk();

        $user->forceFill(['status' => 'disabled'])->save();

        $this->get('/account/profile')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
