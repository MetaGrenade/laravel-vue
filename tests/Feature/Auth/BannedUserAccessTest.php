<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BannedUserAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_banned_users_cannot_login(): void
    {
        $user = User::factory()->create([
            'is_banned' => true,
            'banned_at' => now(),
        ]);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_authenticated_banned_users_are_logged_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $user->forceFill([
            'is_banned' => true,
            'banned_at' => now(),
        ])->save();

        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
