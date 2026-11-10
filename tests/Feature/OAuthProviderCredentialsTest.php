<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\OAuth\OAuthProviders;
use App\Support\Security\SignInMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A provider's button is offered only when the site has what the provider needs (its credentials, in `.env`) and it is switched on.
 * Before, all three were on by default and each had a button on a site that had set none of them, and pressing one sent the visitor to
 * the provider's own error page. docs/social-login.md, "Setting up a provider".
 *
 * `phpunit.xml` gives every provider credentials; these tests take them away, or give one back, through `config()`.
 */
class OAuthProviderCredentialsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, string>> */
    private const CREDENTIALS = [
        'google' => ['client_id' => 'google-client', 'client_secret' => 'google-secret'],
        'discord' => ['client_id' => 'discord-client', 'client_secret' => 'discord-secret'],
        'steam' => ['api_key' => 'steam-key'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh install: nothing set.
        foreach (self::CREDENTIALS as $provider => $keys) {
            foreach (array_keys($keys) as $key) {
                config(["services.{$provider}.{$key}" => null]);
            }
        }
    }

    private function provideCredentials(string $provider): void
    {
        foreach (self::CREDENTIALS[$provider] as $key => $value) {
            config(["services.{$provider}.{$key}" => $value]);
        }
    }

    /**
     * @return list<string>
     */
    private function offeredOnSignIn(): array
    {
        $keys = [];

        // (A regular closure: an arrow function would capture `$keys` by value and never hand the answer back.)
        $this->get(route('login'))->assertOk()->assertInertia(function (Assert $page) use (&$keys): void {
            $page->where('socialProviders', function ($options) use (&$keys): bool {
                $keys = collect($options)->pluck('key')->all();

                return true;
            });
        });

        return $keys;
    }

    // --- What is offered -------------------------------------------------------------------------------

    #[Test]
    public function a_fresh_install_offers_no_sign_in_button_and_nothing_is_switched_off(): void
    {
        $this->assertSame([], $this->offeredOnSignIn());
        $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('socialProviders', []));

        // The switches are on, as they have always been by default: it is the missing credentials that keep the buttons away.
        $this->assertSame(['google' => true, 'discord' => true, 'steam' => true], OAuthProviders::all());
    }

    #[Test]
    public function a_provider_that_is_set_up_gets_its_button_and_the_others_do_not(): void
    {
        $this->provideCredentials('discord');

        $this->assertSame(['discord'], $this->offeredOnSignIn());
        $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('socialProviders.0.key', 'discord')->has('socialProviders', 1));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function oneCredentialMissing(): array
    {
        return [
            'google without its client id' => ['google', 'client_id'],
            'google without its client secret' => ['google', 'client_secret'],
            'discord without its client id' => ['discord', 'client_id'],
            'discord without its client secret' => ['discord', 'client_secret'],
            'steam without its API key' => ['steam', 'api_key'],
        ];
    }

    #[Test]
    #[DataProvider('oneCredentialMissing')]
    public function every_credential_is_needed(string $provider, string $missing): void
    {
        $this->provideCredentials($provider);
        $this->assertSame([$provider], $this->offeredOnSignIn(), 'the control: with everything set, the button is there');

        config(["services.{$provider}.{$missing}" => null]);

        $this->assertSame([], $this->offeredOnSignIn());
        $this->assertSame([strtoupper("{$provider}_{$missing}")], OAuthProviders::missingCredentials($provider));
    }

    #[Test]
    public function a_blank_value_is_a_missing_one(): void
    {
        // `GOOGLE_CLIENT_ID=` in `.env` (what `.env.example` ships) is an empty string, not null.
        config(['services.google.client_id' => '', 'services.google.client_secret' => '   ']);

        $this->assertFalse(OAuthProviders::isConfigured('google'));
        $this->assertSame(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'], OAuthProviders::missingCredentials('google'));
        $this->assertSame([], $this->offeredOnSignIn());
    }

    #[Test]
    public function a_provider_that_is_switched_off_has_no_button_even_when_it_is_set_up(): void
    {
        $this->provideCredentials('google');
        $this->provideCredentials('steam');
        SystemSetting::set('oauth_providers', array_merge(OAuthProviders::defaults(), ['google' => false]));

        $this->assertSame(['steam'], $this->offeredOnSignIn());
    }

    #[Test]
    public function a_member_is_offered_only_the_providers_that_can_be_used_to_link_an_account(): void
    {
        $this->provideCredentials('google');
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->has('availableSocialProviders', 1)->where('availableSocialProviders.0.key', 'google'));
    }

    // --- Where the addresses lead ----------------------------------------------------------------------

    #[Test]
    public function the_addresses_of_a_provider_that_is_not_set_up_answer_404(): void
    {
        $this->get('/auth/oauth/google/redirect')->assertNotFound();
        $this->get(route('oauth.callback', ['provider' => 'google', 'code' => 'x', 'state' => 'y']))->assertNotFound();
        $this->get('/auth/oauth/steam/redirect')->assertNotFound();
    }

    #[Test]
    public function the_redirect_of_a_provider_that_is_set_up_goes_to_the_provider(): void
    {
        $this->provideCredentials('google');

        $location = (string) $this->get('/auth/oauth/google/redirect')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('google-client', $query['client_id']);
    }

    // --- Where the person is sent back to --------------------------------------------------------------

    #[Test]
    #[DataProvider('noRedirectSetting')]
    public function without_a_redirect_setting_the_person_comes_back_to_the_sites_own_callback_address(?string $setting): void
    {
        $this->provideCredentials('google');
        config(['services.google.redirect' => $setting]);

        $location = (string) $this->get('/auth/oauth/google/redirect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame(route('oauth.callback', ['provider' => 'google']), $query['redirect_uri']);
        $this->assertStringStartsWith('http', $query['redirect_uri'], 'a full address, not a path: the provider cannot send anybody to a path');
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function noRedirectSetting(): array
    {
        return [
            'not set' => [null],
            'an empty line in .env (what `GOOGLE_REDIRECT_URI=` gives)' => [''],
        ];
    }

    #[Test]
    public function a_name_that_is_not_a_provider_is_nothing(): void
    {
        $this->assertSame([], OAuthProviders::missingCredentials('myspace'));
        $this->assertFalse(OAuthProviders::isConfigured('myspace'));
        $this->assertFalse(OAuthProviders::isAvailable('myspace'));
    }

    #[Test]
    public function a_redirect_setting_wins_and_is_the_one_in_the_token_request_too(): void
    {
        $this->provideCredentials('discord');
        config(['services.discord.redirect' => 'https://proxy.example.test/auth/oauth/discord/callback']);

        $location = (string) $this->get('/auth/oauth/discord/redirect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('https://proxy.example.test/auth/oauth/discord/callback', $query['redirect_uri']);

        Http::fake([
            'https://discord.com/api/oauth2/token' => Http::response(['access_token' => 'token']),
            'https://discord.com/api/users/@me' => Http::response(['id' => 'd-1', 'username' => 'ada', 'email' => 'ada@example.test', 'verified' => true]),
        ]);

        $this->withSession(['oauth:state:discord' => 'the-state'])
            ->get(route('oauth.callback', ['provider' => 'discord', 'state' => 'the-state', 'code' => 'the-code']));

        Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/oauth2/token'
            && $request['redirect_uri'] === 'https://proxy.example.test/auth/oauth/discord/callback');
    }

    #[Test]
    public function the_token_request_carries_the_same_fallback_address_as_the_redirect(): void
    {
        $this->provideCredentials('google');
        config(['services.google.redirect' => null]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'g-1', 'email' => 'ada@example.test', 'email_verified' => true]),
        ]);

        $this->withSession(['oauth:state:google' => 'the-state'])
            ->get(route('oauth.callback', ['provider' => 'google', 'state' => 'the-state', 'code' => 'the-code']));

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['redirect_uri'] === route('oauth.callback', ['provider' => 'google']));
    }

    // --- Telling the administrator ---------------------------------------------------------------------

    private function administrator(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    #[Test]
    public function the_system_page_says_what_each_provider_still_needs(): void
    {
        $this->provideCredentials('steam');
        config(['services.google.client_id' => 'google-client']);

        $this->actingAs($this->administrator())->get(route('acp.system'))->assertInertia(fn (Assert $page) => $page
            ->where('oauthProviders.0.key', 'google')
            ->where('oauthProviders.0.configured', false)
            ->where('oauthProviders.0.missing', ['GOOGLE_CLIENT_SECRET'])
            ->where('oauthProviders.1.key', 'discord')
            ->where('oauthProviders.1.missing', ['DISCORD_CLIENT_ID', 'DISCORD_CLIENT_SECRET'])
            ->where('oauthProviders.2.key', 'steam')
            ->where('oauthProviders.2.configured', true)
            ->where('oauthProviders.2.missing', [])
            // What the switches say is a separate thing, and does not change.
            ->where('oauthProviders.0.enabled', true)
            ->where('settings.oauth_providers', ['google' => true, 'discord' => true, 'steam' => true]));
    }

    // --- Who can still get in --------------------------------------------------------------------------

    #[Test]
    public function a_linked_provider_that_cannot_be_used_is_no_way_back_in(): void
    {
        // A member with a made-up address, who has Steam and Google linked: Google is a way back in only while the site can use it.
        $member = User::factory()->create(['email' => 'steam_1@oauth.local']);
        $steam = SocialAccount::create(['user_id' => $member->id, 'provider' => 'steam', 'provider_id' => Str::uuid()->toString()]);
        SocialAccount::create(['user_id' => $member->id, 'provider' => 'google', 'provider_id' => Str::uuid()->toString()]);

        $this->assertFalse(SignInMethods::remainAfterUnlinking($member, $steam), 'Google is linked, but the site has no credentials for it');

        $this->provideCredentials('google');

        $this->assertTrue(SignInMethods::remainAfterUnlinking($member, $steam));
    }
}
