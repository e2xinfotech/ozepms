<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Totp;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTest extends TestCase
{
    public function test_two_factor_setup_with_qr_secret_and_code(): void
    {
        $user = $this->makeUser();

        $begin = $this->actingAs($user)->postJson('/web-api/account/two-factor/begin')->assertOk()->json();
        $this->assertStringStartsWith('otpauth://totp/', $begin['uri']);
        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());

        $this->postJson('/web-api/account/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);

        $codes = $this->postJson('/web-api/account/two-factor/confirm', ['code' => Totp::code($begin['secret'])])
            ->assertOk()->json('recovery_codes');

        $this->assertCount(8, $codes);
        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());
        $this->postJson('/web-api/account/two-factor/begin')->assertStatus(422);
    }

    public function test_confirm_requires_a_six_digit_code(): void
    {
        $this->actingAs($this->makeUser())->postJson('/web-api/account/two-factor/confirm', [])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_turning_two_factor_off_needs_the_password(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($user)->postJson('/web-api/account/two-factor/disable', ['current_password' => 'wrong-one'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['current_password']]]);
        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());

        $this->postJson('/web-api/account/two-factor/disable', ['current_password' => 'Secret-Pass-123'])->assertOk();
        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
    }

    public function test_two_factor_cannot_be_turned_off_when_mandatory(): void
    {
        config(['ozepms.security.require_2fa.platform_users' => true]);
        $admin = $this->makePlatformAdmin();
        $admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($admin)->postJson('/web-api/account/two-factor/disable', ['current_password' => 'Secret-Pass-123'])->assertStatus(422);
        $this->assertTrue($admin->refresh()->hasTwoFactorEnabled());
    }

    public function test_password_change(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->putJson('/web-api/account/password', [
            'current_password' => 'wrong', 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77',
        ])->assertStatus(422);

        $this->putJson('/web-api/account/password', [
            'current_password' => 'Secret-Pass-123', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['password']]]);

        $this->putJson('/web-api/account/password', [
            'current_password' => 'Secret-Pass-123', 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77',
        ])->assertOk();

        $this->assertTrue(Hash::check('Brand-New-Pass-77', $user->refresh()->password));
    }

    public function test_profile_update_and_language(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->putJson('/web-api/account/profile', ['name' => '', 'locale' => 'xx'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'locale']]]);

        $this->putJson('/web-api/account/profile', ['name' => 'Renée Dupont', 'job_title' => 'GM', 'phone_e164' => '+33612345678', 'locale' => 'fr'])
            ->assertOk()->assertJsonPath('profile.locale', 'fr');

        $this->assertSame('Renée Dupont', $user->refresh()->name);
        $this->get('/account/profile')->assertOk()->assertSee('Mon profil', false);
    }

    public function test_language_switch_translates_pages(): void
    {
        $user = $this->makeUser();

        foreach (['fr' => 'Sécurité et connexion', 'it' => 'Sicurezza e accesso', 'de' => 'Sicherheit &amp; Anmeldung'] as $locale => $title) {
            $this->actingAs($user)->postJson('/web-api/locale', ['locale' => $locale])->assertNoContent();
            $this->get('/account/security')->assertOk()->assertSee($title, false);
        }

        $this->postJson('/web-api/locale', ['locale' => 'xx'])->assertStatus(422);
    }
}
