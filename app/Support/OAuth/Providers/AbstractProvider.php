<?php

namespace App\Support\OAuth\Providers;

use App\Support\OAuth\Contracts\Provider as ProviderContract;
use App\Support\OAuth\Exceptions\OAuthException;
use App\Support\OAuth\OAuthUser;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class AbstractProvider implements ProviderContract
{
    public function __construct(
        protected string $name,
        protected array $config,
        protected Session $session,
        protected UrlGenerator $url,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    abstract public function redirect(): RedirectResponse;

    /**
     * {@inheritdoc}
     */
    abstract public function user(Request $request): OAuthUser;

    /**
     * Generate and store a state parameter for the current provider.
     */
    protected function generateState(): string
    {
        $state = Str::random(40);

        $this->session->put($this->stateSessionKey(), $state);

        return $state;
    }

    /**
     * Ensure that the incoming request has a valid state parameter.
     */
    protected function validateState(Request $request): void
    {
        $expected = $this->session->pull($this->stateSessionKey());

        if ($expected === null) {
            throw new OAuthException('Missing OAuth state. Please try again.');
        }

        if ((string) $request->query('state') !== $expected) {
            throw new OAuthException('Invalid OAuth state received.');
        }
    }

    /**
     * Build the session key used for storing state.
     */
    protected function stateSessionKey(): string
    {
        return 'oauth:state:'.$this->name;
    }
}
