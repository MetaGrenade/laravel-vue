<?php

namespace App\Support\Api;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Notifications\ApiKeyCreated;
use App\Support\EmailVerification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Throwable;

/**
 * The API keys people make for themselves, in Settings → API keys and by signing in over the API (`POST /api/v1/auth/token`); see
 * docs/api-keys.md: a Sanctum token that is limited to some scopes, expires, and can be revoked, rotated and limited by its owner. Every
 * way of making one comes through here, so they all have the same limits, and the owner is told by email each time. Staff make keys of
 * their own kind in Admin → Tokens.
 */
class ApiKeys
{
    /** The most calls an owner can limit a key to, an hour or a day (there is no point in a limit nothing could reach). */
    public const MAX_LIMIT = 10_000_000;

    /** How a form asks for a key's hourly or daily limit: a whole number of calls, or nothing for no limit. */
    public const LIMIT_RULES = ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT];

    public function __construct(private readonly Scopes $scopes) {}

    /**
     * What a person is told about a limit they got wrong, for both of the fields.
     *
     * @return array<string, string>
     */
    public static function limitMessages(): array
    {
        $messages = [];

        foreach (['hourly_quota', 'daily_quota'] as $field) {
            $messages["{$field}.integer"] = 'Enter a whole number of calls, or leave it empty for no limit.';
            $messages["{$field}.min"] = 'A limit must be at least 1 call. Leave it empty for no limit.';
            $messages["{$field}.max"] = 'A limit cannot be more than '.number_format(self::MAX_LIMIT).' calls.';
        }

        return $messages;
    }

    /**
     * How many keys a person can use right now: those that are neither revoked nor expired (one that is being replaced still is).
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
     * How long the old key goes on working after it is rotated, in hours, that a person can pick from (0 is "stop it at once"), and the
     * one picked to begin with.
     *
     * @return array{hours: list<int>, default: int}
     */
    public function rotationChoices(): array
    {
        $hours = array_values(array_unique(array_filter(
            array_map('intval', (array) config('api.keys.rotation_grace_hours', [])),
            fn (int $value) => $value >= 0,
        )));
        sort($hours);

        if ($hours === []) {
            $hours = [0];
        }

        $default = (int) config('api.keys.default_rotation_grace_hours');

        return ['hours' => $hours, 'default' => in_array($default, $hours, true) ? $default : $hours[0]];
    }

    /**
     * What a key made by signing in over the API can do when the request does not say (`api.sign_in.scopes`). If the site has set
     * none, the least there is: reading the profile.
     *
     * @return list<string>
     */
    public function signInScopes(): array
    {
        $scopes = array_values(array_unique(array_map('strval', (array) config('api.sign_in.scopes', []))));

        return $scopes === [] ? ['profile:read'] : $scopes;
    }

    /**
     * How many days a key made by signing in over the API lasts (`api.sign_in.expiry_days`), or null for no end (where the site allows
     * one: see {@see self::create()}).
     */
    public function signInLifetime(): ?int
    {
        $days = config('api.sign_in.expiry_days');

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    /**
     * Make a key. The secret is in the result and nowhere else: it is shown once, and only a hash of it is kept. The person is told by
     * email (a mail that cannot be sent is reported, and does not undo the key).
     *
     * @param  list<string>  $scopes  What it is limited to (each one a scope the site has, or `*` for all of them).
     * @param  int|null  $expiresInDays  How long it lasts, or null for as long as it is not revoked. Where the site does not allow a key
     *                                   that never ends, null is the default lifetime instead.
     * @param  int|null  $hourlyLimit  How many calls it may make an hour, if it is to be limited.
     * @param  int|null  $dailyLimit  How many calls it may make a day, if it is to be limited.
     * @param  string  $how  How it came to be made, for the email (one of the `ApiKeyCreated` constants).
     *
     * @throws ApiKeyRejected the person has not verified their address (where the site asks for it), or already has as many keys as
     *                        they may
     * @throws InvalidArgumentException a scope the site does not have (a mistake in the code, not something a person did)
     */
    public function create(User $user, string $name, array $scopes, ?int $expiresInDays, ?int $hourlyLimit = null, ?int $dailyLimit = null, string $how = ApiKeyCreated::SETTINGS): NewAccessToken
    {
        $scopes = $this->checked($scopes);
        $this->mustBeAbleToMakeKeys($user);
        $days = $this->lifetime($expiresInDays);

        $key = DB::transaction(function () use ($user, $name, $scopes, $days, $hourlyLimit, $dailyLimit) {
            $this->takeTurns($user);

            if ($this->activeCount($user) >= $this->max()) {
                throw ApiKeyRejected::tooMany($this->max());
            }

            return $this->mint($user, $name, $scopes, $days, $hourlyLimit, $dailyLimit, null);
        });

        $this->announce($user, $key->accessToken, $how);

        return $key;
    }

    /**
     * Rotate a key: make a new one like it (same name, scopes and limits, and the same length of life from now) and let the old one go
     * on working for a grace period, so whatever uses it has time to be given the new one. With no grace period the old one stops at
     * once. The old one is not counted again as the key it is replaced by: a key that is being replaced cannot be rotated.
     *
     * Needs room for the new key beside the old one, so with a grace period a person at the limit on keys must revoke one first (or
     * rotate with no grace period, which frees the old one's place).
     *
     * @param  int  $graceHours  How long the old key goes on working, in hours (0 for not at all).
     *
     * @throws ApiKeyRejected it cannot be rotated, or there is no room for the new key
     */
    public function rotate(User $user, PersonalAccessToken $key, int $graceHours): NewAccessToken
    {
        $this->mustBeAbleToMakeKeys($user);

        [$new, $old] = DB::transaction(function () use ($user, $key, $graceHours) {
            $this->takeTurns($user);

            // Read again under the lock: two requests to rotate the same key must not both go through.
            /** @var PersonalAccessToken $old */
            $old = $user->tokens()->lockForUpdate()->findOrFail($key->getKey());

            if ($old->isRevoked() || $old->isExpired()) {
                throw ApiKeyRejected::cannotRotate('This key no longer works, so there is nothing to replace. Make a new key instead.');
            }

            if ($old->rotated_at !== null) {
                throw ApiKeyRejected::cannotRotate('This key has already been replaced, and is only working out its grace period.');
            }

            $scopes = array_values(array_filter(
                array_unique((array) $old->abilities),
                fn (string $ability) => $ability === Scopes::FULL || in_array($ability, $this->scopes->keys(), true),
            ));

            if ($scopes === []) {
                throw ApiKeyRejected::cannotRotate('This key holds nothing the site recognises, so a copy of it would do nothing. Make a new key instead.');
            }

            // The same length of life from now. (Rounded: a key's end is a moment after its start, by however long the request took.)
            $days = $old->expires_at === null || $old->created_at === null
                ? null
                : max(1, (int) round($old->created_at->diffInDays($old->expires_at, true)));

            $now = now();

            if ($graceHours === 0) {
                // Out of the way first, so its place is the one the new key takes.
                $old->forceFill(['rotated_at' => $now, 'revoked_at' => $now])->save();
            }

            if ($this->activeCount($user) >= $this->max()) {
                throw $graceHours === 0 ? ApiKeyRejected::tooMany($this->max()) : ApiKeyRejected::noRoomToRotate($this->max());
            }

            $new = $this->mint($user, $old->name, $scopes, $this->lifetime($days), $old->hourly_quota, $old->daily_quota, $old->limits_set_by_staff_at);

            if ($graceHours > 0) {
                $until = $now->copy()->addHours($graceHours);

                $old->forceFill([
                    'rotated_at' => $now,
                    'expires_at' => $old->expires_at !== null && $old->expires_at->lt($until) ? $old->expires_at : $until,
                ])->save();
            }

            return [$new, $old];
        });

        $this->announce($user, $new->accessToken, ApiKeyCreated::ROTATION, $old->name, $graceHours > 0 ? $old->expires_at : null);

        return $new;
    }

    /**
     * Let the owner limit a key: how many calls it may make an hour and a day (null for no limit). Not once staff have set the limits,
     * which are theirs to change.
     *
     * @throws ApiKeyRejected staff set them
     */
    public function setLimits(PersonalAccessToken $key, ?int $hourlyLimit, ?int $dailyLimit): void
    {
        if ($key->hasLimitsFromStaff()) {
            throw ApiKeyRejected::limitsFromStaff();
        }

        $key->forceFill(['hourly_quota' => $hourlyLimit, 'daily_quota' => $dailyLimit])->save();
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

    /**
     * Tell the owner a key was made on their account. A mail that cannot be sent is reported: it must not turn a key that was made into
     * an error, when the secret is already gone with the response that would have carried it.
     *
     * @param  string|null  $replaced  The name of the key it replaces (rotation).
     * @param  CarbonInterface|null  $replacedUntil  When the key it replaces stops working (rotation; null if it stopped at once).
     */
    public function announce(User $user, SanctumPersonalAccessToken $key, string $how, ?string $replaced = null, ?CarbonInterface $replacedUntil = null): void
    {
        $catalogue = $this->scopes->all();
        $abilities = (array) $key->abilities;

        try {
            $user->notify(new ApiKeyCreated(
                name: $key->name,
                how: $how,
                scopes: array_values(array_map(
                    fn (string $ability) => $catalogue[$ability]->label ?? $ability,
                    array_filter($abilities, fn (string $ability) => $ability !== Scopes::FULL),
                )),
                fullAccess: in_array(Scopes::FULL, $abilities, true),
                expiresAt: $key->expires_at,
                ip: request()->ip(),
                replaced: $replaced,
                replacedUntil: $replacedUntil,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function checked(array $scopes): array
    {
        $scopes = array_values(array_unique($scopes));

        foreach ($scopes as $scope) {
            if ($scope !== Scopes::FULL) {
                $this->scopes->get($scope);
            }
        }

        return $scopes;
    }

    /**
     * @throws ApiKeyRejected
     */
    private function mustBeAbleToMakeKeys(User $user): void
    {
        if (EmailVerification::isRequired() && ! $user->hasVerifiedEmail()) {
            throw ApiKeyRejected::unverified();
        }
    }

    /**
     * How long a key lasts: what was asked for, or where nothing was and the site does not allow a key that never ends, the default.
     */
    private function lifetime(?int $days): ?int
    {
        if ($days !== null) {
            return $days;
        }

        $choices = $this->expiryChoices();

        return $choices['never'] ? null : $choices['default'];
    }

    /**
     * One person's requests take turns, so two of them at the limit cannot both make the last key. The row locked is the person's own,
     * which always exists (the keys themselves may not).
     */
    private function takeTurns(User $user): void
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<string>  $scopes
     * @param  CarbonInterface|null  $limitsSetByStaffAt  When staff set the limits being given to it, if they did.
     */
    private function mint(User $user, string $name, array $scopes, ?int $days, ?int $hourlyLimit, ?int $dailyLimit, ?CarbonInterface $limitsSetByStaffAt): NewAccessToken
    {
        $key = $user->createToken($name, $scopes, $days === null ? null : now()->addDays($days));

        if ($hourlyLimit !== null || $dailyLimit !== null) {
            $key->accessToken->forceFill([
                'hourly_quota' => $hourlyLimit,
                'daily_quota' => $dailyLimit,
                'limits_set_by_staff_at' => $limitsSetByStaffAt,
            ])->save();
        }

        return $key;
    }
}
