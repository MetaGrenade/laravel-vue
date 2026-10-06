<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_challenge_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->withSession(['two_factor:id' => $user->id]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/two-factor-challenge', ['code' => '000000'])->assertStatus(302);
        }

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertStatus(429);
    }

    public function test_password_reset_requests_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('password.email'), ['email' => 'someone@example.com']);
        }

        $this->post(route('password.email'), ['email' => 'someone@example.com'])->assertStatus(429);
    }

    public function test_password_confirmation_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-password']);
        }

        $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-password'])->assertStatus(429);
    }
}
