<?php

namespace App\Support\OAuth;

use App\Support\OAuth\Exceptions\OAuthException;
use App\Support\OAuth\Providers\DiscordProvider;
use App\Support\OAuth\Providers\GoogleProvider;
use App\Support\OAuth\Providers\SteamProvider;
use Illuminate\Support\Arr;

class ProviderRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected const PROVIDERS = [
        'google' => [
            'label' => 'Google',
            'description' => 'Use your Google account for sign in and account recovery.',
            'provider' => GoogleProvider::class,
        ],
        'discord' => [
            'label' => 'Discord',
            'description' => 'Connect with Discord to reach community features.',
            'provider' => DiscordProvider::class,
        ],
        'steam' => [
            'label' => 'Steam',
            'description' => 'Verify your Steam profile for gaming integrations.',
            'provider' => SteamProvider::class,
        ],
    ];

    /**
     * Determine if the provider is supported.
     */
    public static function supports(string $name): bool
    {
        return array_key_exists(strtolower($name), self::PROVIDERS);
    }

    /**
     * Get provider metadata by name.
     *
     * @return array<string, mixed>
     */
    public static function get(string $name): array
    {
        $key = strtolower($name);

        if (! isset(self::PROVIDERS[$key])) {
            throw new OAuthException("Unsupported social provider [{$name}].");
        }

        return self::PROVIDERS[$key];
    }

    /**
     * Get the configured provider class name.
     */
    public static function providerClass(string $name): string
    {
        return (string) Arr::get(self::get($name), 'provider');
    }

    /**
     * Get the metadata for all supported providers.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::PROVIDERS;
    }
}
