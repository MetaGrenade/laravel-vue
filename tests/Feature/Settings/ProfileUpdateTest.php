<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'nickname' => 'Test User',
                'email' => 'test@example.com',
                'avatar_url' => ' https://cdn.example.com/avatar.png ',
                'profile_bio' => ' Passionate about testing. ',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('status', 'verification-link-sent');

        $user->refresh();

        $this->assertSame('Test User', $user->nickname);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('https://cdn.example.com/avatar.png', $user->avatar_url);
        $this->assertSame('Passionate about testing.', $user->profile_bio);
    }

    public function test_email_verification_notification_is_sent_when_email_is_updated()
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'original@example.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'nickname' => 'New Nickname',
                'email' => 'new@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'nickname' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->fresh());
    }
}
