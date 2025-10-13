<?php

namespace App\Support\OAuth\Providers;

use App\Support\OAuth\Exceptions\OAuthException;
use App\Support\OAuth\OAuthUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SteamProvider extends AbstractProvider
{
    public function redirect(): RedirectResponse
    {
        $state = $this->generateState();

        $returnTo = $this->url->route('oauth.callback', ['provider' => 'steam'], true);
        $returnTo .= (str_contains($returnTo, '?') ? '&' : '?').'state='.$state;

        $realm = rtrim((string) ($this->config['realm'] ?? config('app.url')), '/');

        $params = [
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $returnTo,
            'openid.realm' => $realm,
            'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ];

        $uri = 'https://steamcommunity.com/openid/login?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return new RedirectResponse($uri);
    }

    public function user(Request $request): OAuthUser
    {
        $this->validateState($request);

        if ($request->query('openid_mode') === 'cancel') {
            throw new OAuthException('Steam authentication was cancelled.');
        }

        $claimedId = (string) $request->query('openid_claimed_id');

        if ($claimedId === '') {
            throw new OAuthException('Steam did not return a valid identifier.');
        }

        $verificationPayload = $this->verificationPayload($request);
        $verificationPayload['openid.mode'] = 'check_authentication';

        $response = Http::asForm()->post('https://steamcommunity.com/openid/login', $verificationPayload);

        if ($response->failed() || ! Str::contains($response->body(), 'is_valid:true')) {
            throw new OAuthException('Unable to verify Steam login response.');
        }

        $steamId = $this->extractSteamId($claimedId);

        if ($steamId === null) {
            throw new OAuthException('Unable to determine Steam account identifier.');
        }

        $profile = $this->fetchSteamProfile($steamId);

        return new OAuthUser(
            id: $steamId,
            nickname: $profile['nickname'],
            name: $profile['name'],
            email: null,
            avatar: $profile['avatar'],
            raw: [
                'claimed_id' => $claimedId,
                'profile' => $profile['raw'],
            ],
        );
    }

    /**
     * Build the verification payload from the incoming request.
     *
     * @return array<string, mixed>
     */
    protected function verificationPayload(Request $request): array
    {
        $payload = [];

        foreach ($request->query() as $key => $value) {
            if (! str_starts_with($key, 'openid_')) {
                continue;
            }

            $payload[str_replace('_', '.', $key)] = $value;
        }

        return $payload;
    }

    /**
     * Attempt to extract the Steam ID from the claimed identifier URL.
     */
    protected function extractSteamId(string $claimedId): ?string
    {
        $id = Str::afterLast($claimedId, '/');

        return $id !== '' ? $id : null;
    }

    /**
     * Fetch additional profile information from the Steam Web API when available.
     *
     * @return array{nickname: ?string, name: ?string, avatar: ?string, raw: array|null}
     */
    protected function fetchSteamProfile(string $steamId): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! $apiKey) {
            return [
                'nickname' => null,
                'name' => null,
                'avatar' => null,
                'raw' => null,
            ];
        }

        $response = Http::acceptJson()->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', [
            'key' => $apiKey,
            'steamids' => $steamId,
        ]);

        if ($response->failed()) {
            return [
                'nickname' => null,
                'name' => null,
                'avatar' => null,
                'raw' => null,
            ];
        }

        $player = Arr::get($response->json(), 'response.players.0', []);

        return [
            'nickname' => Arr::get($player, 'personaname'),
            'name' => Arr::get($player, 'realname'),
            'avatar' => Arr::get($player, 'avatarfull') ?: Arr::get($player, 'avatar'),
            'raw' => $player,
        ];
    }
}
