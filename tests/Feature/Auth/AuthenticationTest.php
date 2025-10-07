<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_the_challenge_screen()
    {
        $user = User::factory()->create();

        $secret = TwoFactorAuthenticator::generateSecret();

        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes([
                'TEST-ONE-USED',
            ]),
        ])->save();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('two_factor:id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_complete_two_factor_challenge_with_authenticator_code()
    {
        Carbon::setTestNow($now = Carbon::create(2024, 1, 1, 12));

        $user = User::factory()->create();

        $secret = TwoFactorAuthenticator::generateSecret();
        $code = TwoFactorAuthenticator::code($secret, $now->timestamp);

        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes([
                'TEST-RECOVERY-CODE',
            ]),
        ])->save();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $response = $this->post(route('two-factor.login'), [
            'code' => $code,
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionMissing('two_factor:id');
        $this->assertAuthenticatedAs($user);

        Carbon::setTestNow();
    }

    public function test_users_can_complete_two_factor_challenge_with_recovery_code()
    {
        $user = User::factory()->create();

        $secret = TwoFactorAuthenticator::generateSecret();
        $recoveryCode = 'ABCD-EFGH-IJKL';

        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes([
                $recoveryCode,
                'WXYZ-MNOP-QRST',
            ]),
        ])->save();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $response = $this->post(route('two-factor.login'), [
            'recovery_code' => $recoveryCode,
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $remainingCodes = TwoFactorAuthenticator::decryptRecoveryCodes($user->two_factor_recovery_codes);

        $this->assertNotContains($recoveryCode, $remainingCodes);
        $this->assertContains('WXYZ-MNOP-QRST', $remainingCodes);
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
