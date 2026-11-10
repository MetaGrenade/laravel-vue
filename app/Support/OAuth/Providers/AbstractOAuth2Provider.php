<?php

namespace App\Support\OAuth\Providers;

use App\Support\OAuth\Exceptions\OAuthException;
use App\Support\OAuth\OAuthUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

abstract class AbstractOAuth2Provider extends AbstractProvider
{
    /**
     * Get the base scopes to request.
     *
     * @return list<string>
     */
    protected function scopes(): array
    {
        return [];
    }

    /**
     * Get the authorization URL for the provider.
     */
    abstract protected function authorizationUrl(): string;

    /**
     * Get the token URL for the provider.
     */
    abstract protected function tokenUrl(): string;

    /**
     * Map the raw user array to an OAuthUser instance. The tokens are not passed on: they were used to fetch this array, and that is all
     * they are for.
     *
     * @param  array<string, mixed>  $user
     */
    abstract protected function mapUserToObject(array $user): OAuthUser;

    /**
     * {@inheritdoc}
     */
    public function redirect(): RedirectResponse
    {
        $state = $this->generateState();

        $query = array_merge($this->codeFields($state), $this->additionalCodeFields());

        $uri = $this->authorizationUrl().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return new RedirectResponse($uri);
    }

    /**
     * {@inheritdoc}
     */
    public function user(Request $request): OAuthUser
    {
        if ($request->query('error')) {
            throw new OAuthException('Authorization was denied.');
        }

        $this->validateState($request);

        $code = (string) $request->query('code');

        if ($code === '') {
            throw new OAuthException('Missing authorization code from provider.');
        }

        $tokenResponse = $this->requestAccessToken($code);

        $accessToken = (string) Arr::get($tokenResponse, 'access_token');

        if ($accessToken === '') {
            throw new OAuthException('Failed to retrieve an access token from provider.');
        }

        $user = $this->getUserByToken($accessToken);

        return $this->mapUserToObject($user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getUserByToken(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->userInfoUrl());

        if ($response->failed()) {
            throw new OAuthException('Unable to fetch user details from provider.');
        }

        return $response->json() ?? [];
    }

    /**
     * Get the user info endpoint URL.
     */
    abstract protected function userInfoUrl(): string;

    /**
     * Build the parameters for the authorization code request.
     *
     * @return array<string, mixed>
     */
    protected function codeFields(string $state): array
    {
        return [
            'client_id' => Arr::get($this->config, 'client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ];
    }

    /**
     * Where the provider sends the person back to: the `redirect` setting when there is one, otherwise the site's own callback address
     * (what `.env.example` writes out as `${APP_URL}/auth/oauth/<provider>/callback`). The same value goes with the code and with the token
     * request, which the provider checks against each other.
     */
    protected function redirectUri(): string
    {
        $configured = Arr::get($this->config, 'redirect');

        return is_string($configured) && $configured !== ''
            ? $configured
            : $this->url->route('oauth.callback', ['provider' => $this->name], true);
    }

    /**
     * Additional fields for the authorization request.
     *
     * @return array<string, mixed>
     */
    protected function additionalCodeFields(): array
    {
        return [];
    }

    /**
     * Request the access token from the provider.
     *
     * @return array<string, mixed>
     */
    protected function requestAccessToken(string $code): array
    {
        $fields = array_merge(
            [
                'client_id' => Arr::get($this->config, 'client_id'),
                'client_secret' => Arr::get($this->config, 'client_secret'),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
                'code' => $code,
            ],
            $this->additionalTokenFields($code),
        );

        $response = Http::asForm()->acceptJson()->post($this->tokenUrl(), $fields);

        if ($response->failed()) {
            throw new OAuthException('Unable to exchange authorization code for access token.');
        }

        return $response->json() ?? [];
    }

    /**
     * Additional fields for the token exchange.
     *
     * @return array<string, mixed>
     */
    protected function additionalTokenFields(string $code): array
    {
        return [];
    }
}
