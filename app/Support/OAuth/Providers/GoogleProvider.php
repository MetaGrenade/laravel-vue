<?php

namespace App\Support\OAuth\Providers;

use App\Support\OAuth\OAuthUser;
use Illuminate\Support\Arr;

class GoogleProvider extends AbstractOAuth2Provider
{
    protected function scopes(): array
    {
        return ['openid', 'profile', 'email'];
    }

    protected function authorizationUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function userInfoUrl(): string
    {
        return 'https://openidconnect.googleapis.com/v1/userinfo';
    }

    protected function additionalCodeFields(): array
    {
        return [
            'prompt' => 'select_account',
        ];
    }

    protected function mapUserToObject(array $user, array $tokenResponse, string $accessToken): OAuthUser
    {
        return new OAuthUser(
            id: (string) Arr::get($user, 'sub', Arr::get($user, 'id')),
            nickname: Arr::get($user, 'given_name'),
            name: Arr::get($user, 'name'),
            email: Arr::get($user, 'email'),
            avatar: Arr::get($user, 'picture'),
            raw: $user,
            accessToken: $accessToken,
            refreshToken: Arr::get($tokenResponse, 'refresh_token'),
            expiresIn: Arr::get($tokenResponse, 'expires_in'),
        );
    }
}
