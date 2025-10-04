<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $user = User::factory()->create([
            'avatar_url' => 'https://cdn.example.com/current-avatar.png',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'nickname' => 'Test User',
                'email' => 'test@example.com',
                'profile_bio' => ' Passionate about testing. ',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->nickname);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('https://cdn.example.com/current-avatar.png', $user->avatar_url);
        $this->assertSame('Passionate about testing.', $user->profile_bio);
    }

    public function test_avatar_can_be_uploaded_and_old_file_is_removed()
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $oldPath = 'avatars/'.$user->id.'/old-avatar.png';
        Storage::disk('public')->put($oldPath, 'old-avatar');

        $user->forceFill([
            'avatar_url' => Storage::disk('public')->url($oldPath),
        ])->save();

        $file = UploadedFile::fake()->image('avatar.jpg', 320, 320);

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'nickname' => 'Updated User',
                'email' => 'updated@example.com',
                'avatar' => $file,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Updated User', $user->nickname);
        $this->assertSame('updated@example.com', $user->email);
        $this->assertNull($user->email_verified_at);

        $storedFiles = Storage::disk('public')->files('avatars/'.$user->id);
        $this->assertCount(1, $storedFiles);
        $this->assertNotSame($oldPath, $storedFiles[0]);
        Storage::disk('public')->assertExists($storedFiles[0]);
        Storage::disk('public')->assertMissing($oldPath);
        $this->assertSame(Storage::disk('public')->url($storedFiles[0]), $user->avatar_url);
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

    public function test_email_field_is_optional_when_not_changed()
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'nickname' => 'Updated Nickname',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Updated Nickname', $user->nickname);
        $this->assertSame('existing@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
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
