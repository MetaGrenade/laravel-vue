<?php

namespace App\Support\OAuth;

use App\Support\OAuth\Contracts\Provider;
use App\Support\OAuth\Exceptions\OAuthException;
use App\Support\OAuth\OAuthProviders;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class OAuthManager
{
    /**
     * The provider instances.
     *
     * @var array<string, Provider>
     */
    protected array $drivers = [];

    public function __construct(
        protected Application $app,
        protected Session $session,
        protected UrlGenerator $url,
    ) {
    }

    /**
     * Get a provider implementation.
     */
    public function driver(string $name): Provider
    {
        $name = Str::lower($name);

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        return $this->drivers[$name] = $this->createDriver($name);
    }

    /**
     * Create a new provider instance.
     */
    protected function createDriver(string $name): Provider
    {
        if (! ProviderRegistry::supports($name)) {
            throw new OAuthException("Unsupported social provider [{$name}].");
        }

        if (! OAuthProviders::isEnabled($name)) {
            throw new OAuthException("Social provider [{$name}] is currently disabled.");
        }

        $config = Arr::wrap(config("services.{$name}"));

        $providerClass = ProviderRegistry::providerClass($name);

        return new $providerClass($name, $config, $this->session, $this->url);
    }
}
