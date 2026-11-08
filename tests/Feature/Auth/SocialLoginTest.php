<?php

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\OAuth\Contracts\Provider;
use App\Support\OAuth\OAuthManager;
use App\Support\OAuth\OAuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    // --- What is kept from a provider -------------------------------------------------------------------

    /**
     * What each provider answers the token request and the profile request with, as far as the site is concerned. The profile carries a
     * few things the site does not use (a locale, whether two-factor is on) so that "kept nothing it does not use" is something a test can
     * see.
     *
     * @return array<string, array{string, string, string, array<string, mixed>, string}>
     */
    public static function providersThatHandOutTokens(): array
    {
        return [
            'google' => [
                'google',
                'https://oauth2.googleapis.com/token',
                'https://openidconnect.googleapis.com/v1/userinfo',
                ['sub' => 'g-1', 'name' => 'Ada Lovelace', 'given_name' => 'Ada', 'email' => 'ada@example.test', 'email_verified' => true, 'picture' => 'https://example.test/ada.png', 'locale' => 'locale-the-site-does-not-use'],
                'g-1',
            ],
            'discord' => [
                'discord',
                'https://discord.com/api/oauth2/token',
                'https://discord.com/api/users/@me',
                ['id' => 'd-1', 'username' => 'ada', 'global_name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'verified' => true, 'avatar' => null, 'locale' => 'locale-the-site-does-not-use', 'mfa_enabled' => true],
                'd-1',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    #[DataProvider('providersThatHandOutTokens')]
    public function test_a_sign_in_keeps_who_the_person_is_and_none_of_what_the_provider_handed_out(string $provider, string $tokenUrl, string $profileUrl, array $profile, string $providerId): void
    {
        config(["services.{$provider}" => ['client_id' => 'client', 'client_secret' => 'secret', 'redirect' => 'https://example.test/callback']]);

        Http::fake([
            $tokenUrl => Http::response(['access_token' => 'access-token-one', 'refresh_token' => 'refresh-token-two', 'id_token' => 'id-token-three', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            $profileUrl => Http::response($profile),
        ]);

        $this->withSession(["oauth:state:{$provider}" => 'the-state'])
            ->get(route('oauth.callback', ['provider' => $provider, 'state' => 'the-state', 'code' => 'the-code']))
            ->assertRedirect(route('dashboard', absolute: false));

        $account = SocialAccount::query()->sole();
        $this->assertSame($providerId, $account->provider_id);
        $this->assertSame('ada@example.test', $account->email);
        $this->assertSame('Ada Lovelace', $account->name);

        $stored = (string) json_encode(DB::table('social_accounts')->get());

        foreach (['access-token-one', 'refresh-token-two', 'id-token-three', 'locale-the-site-does-not-use'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $stored, "{$leftover} was written to social_accounts");
        }
    }

    public function test_the_table_has_a_column_for_who_the_person_is_and_none_for_anything_else(): void
    {
        $columns = Schema::getColumnListing('social_accounts');
        sort($columns);

        $this->assertSame(
            ['avatar', 'created_at', 'email', 'id', 'name', 'nickname', 'provider', 'provider_id', 'updated_at', 'user_id'],
            $columns,
            'A column here is kept for every member who signs in with a provider, and shows up in every backup. A token, a secret or the provider\'s raw profile does not belong in it; if the new column is for something else, change this list on purpose.',
        );
    }

    public function test_what_a_provider_says_about_a_person_has_no_place_for_a_token(): void
    {
        $properties = array_map(fn ($property) => $property->getName(), (new ReflectionClass(OAuthUser::class))->getProperties());

        $this->assertNotEmpty($properties);

        foreach ($properties as $property) {
            $this->assertDoesNotMatchRegularExpression('/token|secret|expire/i', $property, 'A token handed on from the provider class is a token somebody can save, log or dump.');
        }
    }

    public function test_the_migration_takes_the_tokens_a_site_already_has_out_of_its_table(): void
    {
        $migration = require database_path('migrations/2026_11_12_000000_stop_keeping_provider_tokens.php');
        $columns = ['access_token', 'refresh_token', 'token_expires_at', 'data'];
        $user = null;

        // DDL commits the test's transaction on MySQL, so what this test writes is removed by hand and the schema is put back before it ends.
        try {
            $migration->down();
            $this->assertTrue(Schema::hasColumns('social_accounts', $columns), 'a site from before this change has them');

            $user = User::factory()->create();
            DB::table('social_accounts')->insert([
                'user_id' => $user->id,
                'provider' => 'google',
                'provider_id' => 'g-9',
                'email' => 'old@example.test',
                'access_token' => 'old-access-token',
                'refresh_token' => 'old-refresh-token',
                'token_expires_at' => now(),
                'data' => json_encode(['locale' => 'old-locale']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration->up();

            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn('social_accounts', $column), "{$column} is still there");
            }

            $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-9', 'email' => 'old@example.test']);

            $migration->up();
            $this->assertFalse(Schema::hasColumn('social_accounts', 'access_token'), 'running it again changes nothing');
        } finally {
            if (Schema::hasColumn('social_accounts', 'access_token')) {
                $migration->up();
            }

            if ($user) {
                User::query()->whereKey($user->id)->delete();
            }
        }
    }

    // --- Signing in and linking -------------------------------------------------------------------------

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
            emailVerified: true,
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
