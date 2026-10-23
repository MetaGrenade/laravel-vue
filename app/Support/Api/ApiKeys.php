<?php

namespace App\Support\Api;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\EmailVerification;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;

/**
 * The API keys people make for themselves, in Settings → API keys and by signing in over the API (`POST /api/v1/auth/token`); see
 * docs/api-keys.md: a Sanctum token that is limited to some scopes, expires, and can be revoked. Both ways come through `create()`, so
 * they have the same limits. Staff make keys of their own kind in Admin → Tokens.
 */
class ApiKeys
{
    public function __construct(private readonly Scopes $scopes) {}

    /**
     * How many keys a person can use right now: those that are neither revoked nor expired.
     */
    public function activeCount(User $user): int
    {
        return $user->tokens()->active()->count();
    }

    /**
     * How many keys a person may have at once.
     */
    public function max(): int
    {
        return max(1, (int) config('api.keys.max_active', 10));
    }

    /**
     * The lifetimes a person can pick from, in days, the one picked to begin with (null if there is none), and whether "never" is among
     * them. A site that offers no lifetime at all must let a key last, or nobody could make one.
     *
     * @return array{days: list<int>, default: int|null, never: bool}
     */
    public function expiryChoices(): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) config('api.keys.expiry_days', [])),
            fn (int $value) => $value > 0,
        )));
        sort($days);

        $default = (int) config('api.keys.default_expiry_days');

        return [
            'days' => $days,
            'default' => in_array($default, $days, true) ? $default : ($days[0] ?? null),
            'never' => (bool) config('api.keys.allow_no_expiry', true) || $days === [],
        ];
    }

    /**
     * Make a key. The secret is in the result and nowhere else: it is shown once, and only a hash of it is kept.
     *
     * @param  list<string>  $scopes  What it is limited to (each one a scope the site has, or `*` for all of them).
     * @param  int|null  $expiresInDays  How long it lasts, or null for as long as it is not revoked. Where the site does not allow a key
     *                                   that never ends, null is the default lifetime instead.
     *
     * @throws ApiKeyRejected the person has not verified their address (where the site asks for it), or already has as many keys as
     *                        they may
     * @throws InvalidArgumentException a scope the site does not have (a mistake in the code, not something a person did)
     */
    public function create(User $user, string $name, array $scopes, ?int $expiresInDays): NewAccessToken
    {
        $scopes = array_values(array_unique($scopes));

        foreach ($scopes as $scope) {
            if ($scope !== Scopes::FULL) {
                $this->scopes->get($scope);
            }
        }

        if (EmailVerification::isRequired() && ! $user->hasVerifiedEmail()) {
            throw ApiKeyRejected::unverified();
        }

        if ($expiresInDays === null) {
            $choices = $this->expiryChoices();
            $expiresInDays = $choices['never'] ? null : $choices['default'];
        }

        return DB::transaction(function () use ($user, $name, $scopes, $expiresInDays) {
            // One person's requests take turns, so two of them at the limit cannot both make the last key. The row locked is the
            // person's own, which always exists (the keys themselves may not).
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($this->activeCount($user) >= $this->max()) {
                throw ApiKeyRejected::tooMany($this->max());
            }

            return $user->createToken($name, $scopes, $expiresInDays === null ? null : now()->addDays($expiresInDays));
        });
    }

    /**
     * Stop a key working. It stays, as revoked, so the person can see it was and what it did.
     */
    public function revoke(PersonalAccessToken $key): void
    {
        if (! $key->isRevoked()) {
            $key->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * Take a key off the list for good, with the record of what it did.
     */
    public function remove(PersonalAccessToken $key): void
    {
        $key->delete();
    }
}
