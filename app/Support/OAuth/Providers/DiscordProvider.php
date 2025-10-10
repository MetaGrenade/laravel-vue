<?php

namespace App\Support\OAuth\Providers;

use App\Support\OAuth\OAuthUser;
use Illuminate\Support\Arr;

class DiscordProvider extends AbstractOAuth2Provider
{
    protected function scopes(): array
    {
        return ['identify', 'email'];
    }

    protected function authorizationUrl(): string
    {
        return 'https://discord.com/api/oauth2/authorize';
    }

    protected function tokenUrl(): string
    {
        return 'https://discord.com/api/oauth2/token';
    }

    protected function userInfoUrl(): string
    {
        return 'https://discord.com/api/users/@me';
    }

    protected function additionalCodeFields(): array
    {
        return [
            'prompt' => 'consent',
        ];
    }

    protected function mapUserToObject(array $user, array $tokenResponse, string $accessToken): OAuthUser
    {
        $username = Arr::get($user, 'username');
        $globalName = Arr::get($user, 'global_name');
        $discriminator = Arr::get($user, 'discriminator');

        $nickname = $globalName ?: $username;

        if ($nickname && $discriminator && $discriminator !== '0') {
            $nickname .= '#'.$discriminator;
        }

        $avatarHash = Arr::get($user, 'avatar');
        $userId = (string) Arr::get($user, 'id');
        $avatarUrl = null;

        if ($avatarHash) {
            $format = str_starts_with($avatarHash, 'a_') ? 'gif' : 'png';
            $avatarUrl = sprintf('https://cdn.discordapp.com/avatars/%s/%s.%s?size=256', $userId, $avatarHash, $format);
        }

        return new OAuthUser(
            id: $userId,
            nickname: $nickname,
            name: $globalName ?: $username,
            email: Arr::get($user, 'email'),
            avatar: $avatarUrl,
            raw: $user,
            accessToken: $accessToken,
            refreshToken: Arr::get($tokenResponse, 'refresh_token'),
            expiresIn: Arr::get($tokenResponse, 'expires_in'),
        );
    }
}
