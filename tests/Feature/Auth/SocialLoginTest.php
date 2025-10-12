<?php

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\OAuth\Contracts\Provider;
use App\Support\OAuth\OAuthManager;
use App\Support\OAuth\OAuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_link_social_account(): void
    {
        $user = User::factory()->create();

        $oauthUser = new OAuthUser(
            id: 'google-123',
            nickname: 'OAuth User',
            name: 'OAuth User',
            email: 'oauth@example.com',
            avatar: 'https://example.com/avatar.png',
            raw: ['id' => 'google-123'],
            accessToken: 'access-token',
            refreshToken: 'refresh-token',
            expiresIn: 3600,
        );

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($oauthUser);

        $manager = Mockery::mock(OAuthManager::class);
        $manager->shouldReceive('driver')->with('google')->andReturn($provider);

        $this->instance(OAuthManager::class, $manager);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'oauth:link_user_id' => $user->id,
                'oauth:link_redirect' => route('security.edit'),
            ])
            ->get(route('oauth.callback', ['provider' => 'google']));

        $response->assertRedirect(route('security.edit'));
        $response->assertSessionHas('status', 'social-account-linked');

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-123',
        ]);
    }

    public function test_social_login_creates_new_user_when_needed(): void
    {
        $oauthUser = new OAuthUser(
            id: 'discord-456',
            nickname: 'DiscordUser',
            name: 'Discord User',
            email: 'discord@example.com',
            avatar: null,
            raw: ['id' => 'discord-456'],
            accessToken: 'token',
            refreshToken: null,
            expiresIn: null,
        );

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($oauthUser);

        $manager = Mockery::mock(OAuthManager::class);
        $manager->shouldReceive('driver')->with('discord')->andReturn($provider);

        $this->instance(OAuthManager::class, $manager);

        $response = $this->get(route('oauth.callback', ['provider' => 'discord']));

        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'discord@example.com')->first();
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_id' => 'discord-456',
        ]);
    }

    public function test_banned_user_cannot_login_via_social_account(): void
    {
        $user = User::factory()->create([
            'is_banned' => true,
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'banned-user',
        ]);

        $oauthUser = new OAuthUser(
            id: 'banned-user',
            nickname: 'Banned',
            name: 'Banned',
            email: $user->email,
            avatar: null,
            raw: [],
        );

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($oauthUser);

        $manager = Mockery::mock(OAuthManager::class);
        $manager->shouldReceive('driver')->with('google')->andReturn($provider);

        $this->instance(OAuthManager::class, $manager);

        $response = $this->get(route('oauth.callback', ['provider' => 'google']));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_can_unlink_social_account(): void
    {
        $user = User::factory()->create();

        $account = SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'steam',
            'provider_id' => Str::uuid()->toString(),
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('settings.social.unlink', ['provider' => 'steam']));

        $response->assertRedirect(route('security.edit'));
        $response->assertSessionHas('status', 'social-account-unlinked');

        $this->assertDatabaseMissing('social_accounts', [
            'id' => $account->id,
        ]);
    }
}
