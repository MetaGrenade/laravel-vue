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

    public function test_session_authenticated_users_are_logged_out_once_banned(): void
    {
        $user = User::factory()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);

        $user->forceFill([
            'is_banned' => true,
            'banned_at' => now(),
        ])->save();

        // Drop the guard's in-memory user so the next request must resolve
        // the user from the session, exactly as a real browser request would.
        $this->app['auth']->forgetGuards();

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
