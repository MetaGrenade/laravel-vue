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

    /**
     * The limiter runs before validation does, so an email that is not text (email[]=x) must reach the form's own error.
     *
     * @param  array<string, string>  $fields
     */
    private function assertEmailThatIsNotTextIsAValidationError(string $uri, array $fields = []): void
    {
        $this->from('/somewhere')
            ->post($uri, [...$fields, 'email' => ['someone@example.com']])
            ->assertRedirect('/somewhere')
            ->assertSessionHasErrors('email');
    }

    public function test_registering_with_an_email_that_is_not_text_is_a_validation_error_not_a_server_error(): void
    {
        $this->assertEmailThatIsNotTextIsAValidationError('/register', [
            'nickname' => 'someone',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    public function test_asking_for_a_reset_link_with_an_email_that_is_not_text_is_a_validation_error_not_a_server_error(): void
    {
        $this->assertEmailThatIsNotTextIsAValidationError(route('password.email', absolute: false));
    }

    public function test_resetting_a_password_with_an_email_that_is_not_text_is_a_validation_error_not_a_server_error(): void
    {
        $this->assertEmailThatIsNotTextIsAValidationError(route('password.store', absolute: false), [
            'token' => 'a-token',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    public function test_emails_that_are_not_text_share_one_allowance_and_do_not_get_round_the_limit(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('password.email'), ['email' => ["someone{$attempt}@example.com"]])->assertSessionHasErrors('email');
        }

        $this->post(route('password.email'), ['email' => ['another@example.com']])->assertStatus(429);
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
