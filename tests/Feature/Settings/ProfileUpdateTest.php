<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

    public function test_user_can_upload_an_avatar()
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_url' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'nickname' => 'Test User',
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('avatar.png', 600, 600),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertNotNull($user->avatarStoragePath());
        $this->assertNotNull($user->avatar_url);
        $this->assertStringContainsString('/storage/', $user->avatar_url);
        $this->assertStringStartsWith('avatars/', $user->avatarStoragePath());
        Storage::disk('public')->assertExists($user->avatarStoragePath());
    }

    public function test_uploading_an_avatar_removes_the_previous_file()
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_url' => 'avatars/original.png',
        ]);

        Storage::disk('public')->put('avatars/original.png', 'original-avatar');

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'nickname' => 'Updated User',
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('replacement.jpg', 512, 512),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertNotSame('avatars/original.png', $user->avatarStoragePath());
        Storage::disk('public')->assertMissing('avatars/original.png');
        Storage::disk('public')->assertExists($user->avatarStoragePath());
    }

    public function test_user_can_remove_an_existing_avatar()
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_url' => 'avatars/remove-me.png',
        ]);

        Storage::disk('public')->put('avatars/remove-me.png', 'avatar');

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'nickname' => 'No Avatar',
                'email' => $user->email,
                'remove_avatar' => true,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertNull($user->avatarStoragePath());
        $this->assertNull($user->avatar_url);
        Storage::disk('public')->assertMissing('avatars/remove-me.png');
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
