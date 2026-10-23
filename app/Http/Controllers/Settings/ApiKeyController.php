<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RotateApiKeyRequest;
use App\Http\Requests\Settings\StoreApiKeyRequest;
use App\Http\Requests\Settings\UpdateApiKeyLimitsRequest;
use App\Models\PersonalAccessToken;
use App\Support\Api\ApiKeys;
use App\Support\Api\Scope;
use App\Support\Api\Scopes;
use App\Support\Localization\DateFormatter;
use App\Support\Usage\Usage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → API keys: a person's own keys for the API, limited to scopes and expiring (see docs/api-keys.md).
 */
class ApiKeyController extends Controller
{
    /** The meter every API request is counted against (`usage:api-calls` in routes/api.php). */
    private const METER = 'api-calls';

    public function __construct(
        private readonly ApiKeys $keys,
        private readonly Scopes $scopes,
        private readonly Usage $usage,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $formatter = DateFormatter::for($user);
        $catalogue = $this->scopes->all();
        $calls = $this->usage->usedByToken($user, self::METER);

        $keys = $user->tokens()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (PersonalAccessToken $key) use ($formatter, $catalogue, $calls) {
                $abilities = (array) $key->abilities;

                return [
                    'id' => $key->id,
                    'name' => $key->name,
                    'status' => $key->isRevoked() ? 'revoked' : ($key->isExpired() ? 'expired' : 'active'),
                    'full_access' => in_array(Scopes::FULL, $abilities, true),
                    // A scope that is no longer one of the site's (a key from before scopes were kept to a list) is shown as it was written.
                    'scopes' => array_values(array_map(
                        fn (string $ability) => ['key' => $ability, 'label' => $catalogue[$ability]->label ?? $ability],
                        array_filter($abilities, fn (string $ability) => $ability !== Scopes::FULL),
                    )),
                    'created_at' => $formatter->iso($key->created_at),
                    'last_used_at' => $formatter->iso($key->last_used_at),
                    'expires_at' => $formatter->iso($key->expires_at),
                    'revoked_at' => $formatter->iso($key->revoked_at),
                    // Replaced by a new key, and only working out its grace period: it cannot be rotated again.
                    'being_replaced' => $key->isBeingReplaced(),
                    'rotated_at' => $formatter->iso($key->rotated_at),
                    'calls_this_month' => $calls[$key->id] ?? 0,
                    'hourly_quota' => $key->hourly_quota,
                    'daily_quota' => $key->daily_quota,
                    // Staff set them, so the owner cannot change them.
                    'limits_from_staff' => $key->hasLimitsFromStaff(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('settings/ApiKeys', [
            'keys' => $keys,
            'scopes' => array_values(array_map(fn (Scope $scope) => $scope->toArray(), $this->scopes->offeredTo($user))),
            'expiry' => $this->keys->expiryChoices(),
            'rotation' => $this->keys->rotationChoices(),
            'limit' => ['active' => $this->keys->activeCount($user), 'max' => $this->keys->max()],
            'max_calls' => ApiKeys::MAX_LIMIT,
            // What the keys add up to: every call made with one is a use of the account's allowance (see docs/usage.md).
            'usage' => $this->usage->status($user, self::METER)->toArray(),
        ]);
    }

    /**
     * Make a key. The secret goes to the next page once and is not kept anywhere.
     */
    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        $key = $this->keys->create(
            $request->user(),
            $request->validated('name'),
            $request->scopesRequested(),
            $request->lifetimeInDays(),
            $request->hourlyLimit(),
            $request->dailyLimit(),
        );

        return to_route('settings.api-keys.index')
            ->with('success', 'API key created.')
            ->with('plain_text_token', $key->plainTextToken);
    }

    /**
     * Rotate a key: make a new one like it, and let the old one go on working for the grace period the person chose. The new secret
     * goes to the next page once, as when a key is made.
     */
    public function rotate(RotateApiKeyRequest $request, int $key): RedirectResponse
    {
        $new = $this->keys->rotate($request->user(), $this->own($request, $key), $request->graceHours());

        return to_route('settings.api-keys.index')
            ->with('success', 'API key rotated.')
            ->with('plain_text_token', $new->plainTextToken);
    }

    /**
     * Change how many calls a key may make an hour and a day, unless staff set its limits.
     */
    public function limits(UpdateApiKeyLimitsRequest $request, int $key): RedirectResponse
    {
        $this->keys->setLimits($this->own($request, $key), $request->hourlyLimit(), $request->dailyLimit());

        return to_route('settings.api-keys.index')->with('success', 'API key limits saved.');
    }

    public function revoke(Request $request, int $key): RedirectResponse
    {
        $this->keys->revoke($this->own($request, $key));

        return to_route('settings.api-keys.index')->with('success', 'API key revoked.');
    }

    public function destroy(Request $request, int $key): RedirectResponse
    {
        $this->keys->remove($this->own($request, $key));

        return to_route('settings.api-keys.index')->with('success', 'API key removed.');
    }

    /**
     * One of the signed-in person's own keys, or a 404 (so nobody learns which numbers are somebody else's).
     */
    private function own(Request $request, int $key): PersonalAccessToken
    {
        /** @var PersonalAccessToken */
        return $request->user()->tokens()->findOrFail($key);
    }
}
