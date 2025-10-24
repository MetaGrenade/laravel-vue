<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Support\OAuth\OAuthProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OAuthProvidersTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_redirect_returns_not_found_when_provider_disabled(): void
    {
        $providers = OAuthProviders::defaults();
        $providers['google'] = false;
        SystemSetting::set('oauth_providers', $providers);

        $this->get('/auth/oauth/google/redirect')->assertNotFound();
    }

    public function test_login_payload_excludes_disabled_providers(): void
    {
        $providers = OAuthProviders::defaults();
        $providers['steam'] = false;
        SystemSetting::set('oauth_providers', $providers);

        $response = $this->get('/login');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('socialProviders', function (array $options): bool {
                $keys = collect($options)->pluck('key')->all();

                return in_array('google', $keys, true)
                    && in_array('discord', $keys, true)
                    && ! in_array('steam', $keys, true);
            })
        );
    }

    public function test_register_payload_excludes_disabled_providers(): void
    {
        $providers = OAuthProviders::defaults();
        $providers['discord'] = false;
        SystemSetting::set('oauth_providers', $providers);

        $response = $this->get('/register');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/Register')
            ->where('socialProviders', function (array $options): bool {
                $keys = collect($options)->pluck('key')->all();

                return in_array('google', $keys, true)
                    && in_array('steam', $keys, true)
                    && ! in_array('discord', $keys, true);
            })
        );
    }
}
