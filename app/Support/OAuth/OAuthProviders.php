<?php

namespace App\Support\OAuth;

use App\Models\SystemSetting;

class OAuthProviders
{
    /**
     * Retrieve the default provider availability map.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (array_keys(ProviderRegistry::all()) as $provider) {
            $defaults[$provider] = true;
        }

        return $defaults;
    }

    /**
     * Get the supported provider keys.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::defaults());
    }

    /**
     * Determine if the given provider is enabled.
     */
    public static function isEnabled(string $provider): bool
    {
        $providers = self::all();
        $key = strtolower($provider);

        if (! array_key_exists($key, $providers)) {
            return false;
        }

        return (bool) $providers[$key];
    }

    /**
     * The settings (as `.env` names, `GOOGLE_CLIENT_ID`) the provider still needs before it can be used: those of `ProviderRegistry::credentials()`
     * that are not set, or are blank.
     *
     * @return list<string>
     */
    public static function missingCredentials(string $provider): array
    {
        $key = strtolower($provider);

        if (! ProviderRegistry::supports($key)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $credential): string => strtoupper($key.'_'.$credential),
            array_filter(
                ProviderRegistry::credentials($key),
                static fn (string $credential): bool => blank(config("services.{$key}.{$credential}")),
            ),
        ));
    }

    /**
     * Whether the site has what the provider needs to sign anybody in (its credentials are in `.env`), whatever the switch says.
     */
    public static function isConfigured(string $provider): bool
    {
        return ProviderRegistry::supports($provider) && self::missingCredentials($provider) === [];
    }

    /**
     * Whether the provider can be used now: switched on in the admin area and set up in `.env`. A button for one that is not would end at
     * the provider's own error page, so it is not offered, and its addresses answer 404.
     */
    public static function isAvailable(string $provider): bool
    {
        return self::isEnabled($provider) && self::isConfigured($provider);
    }

    /**
     * Retrieve the stored provider configuration merged with defaults.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $stored = SystemSetting::get('oauth_providers');
        $defaults = self::defaults();

        if (! is_array($stored)) {
            return $defaults;
        }

        $normalized = [];

        foreach ($defaults as $provider => $enabled) {
            $normalized[$provider] = array_key_exists($provider, $stored)
                ? (bool) $stored[$provider]
                : (bool) $enabled;
        }

        return $normalized;
    }

    /**
     * Normalize an incoming payload of provider toggles.
     *
     * @param  array<string, mixed>  $providers
     * @return array<string, bool>
     */
    public static function normalize(array $providers): array
    {
        $normalized = [];

        foreach (self::defaults() as $provider => $enabled) {
            $normalized[$provider] = (bool) ($providers[$provider] ?? $enabled);
        }

        return $normalized;
    }

    /**
     * Get provider metadata alongside the current enabled state.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(): array
    {
        $configuration = self::all();

        $options = [];

        foreach (ProviderRegistry::all() as $key => $meta) {
            $options[] = [
                'key' => $key,
                'label' => $meta['label'] ?? ucfirst($key),
                'description' => $meta['description'] ?? null,
                'enabled' => (bool) ($configuration[$key] ?? false),
                'configured' => self::isConfigured($key),
                'missing' => self::missingCredentials($key),
            ];
        }

        return $options;
    }

    /**
     * The providers that can be used now (see `isAvailable()`): what the sign-in, registration and Security pages offer.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function availableOptions(): array
    {
        return array_values(array_filter(
            self::options(),
            static fn (array $option): bool => (bool) $option['enabled'] && (bool) $option['configured'],
        ));
    }

    /**
     * Retrieve only the enabled provider options (set up or not: this is the list staff pick from when they connect an account by hand).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function enabledOptions(): array
    {
        return array_values(array_filter(
            self::options(),
            static fn (array $option): bool => (bool) ($option['enabled'] ?? false)
        ));
    }

    /**
     * Get the list of enabled provider keys.
     *
     * @return array<int, string>
     */
    public static function enabledKeys(): array
    {
        return array_map(
            static fn (array $option): string => $option['key'],
            self::enabledOptions()
        );
    }
}
