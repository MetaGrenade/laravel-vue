<?php

namespace Tests\Feature\Settings;

use App\Models\PersonalAccessToken;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ApiKeyCreated;
use App\Support\Api\ApiKeyRejected;
use App\Support\Api\ApiKeys;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\NewAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rotating a key in one step: a new key like the old one, and the old one stops after a grace period the person chooses
 * (docs/api-keys.md).
 */
class ApiKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
        Notification::fake();
    }

    /** Rotate, with the rate limit out of the way (the tests of the limit use `attempt()`). */
    private function rotate(User $user, PersonalAccessToken $key, int $hours = 24)
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        return $this->attempt($user, $key, $hours);
    }

    private function attempt(User $user, PersonalAccessToken $key, int $hours = 24)
    {
        return $this->actingAs($user)->post(route('settings.api-keys.rotate', $key->id), ['grace_hours' => $hours]);
    }

    /**
     * @param  list<string>  $abilities
     * @param  array<string, mixed>  $attributes
     */
    private function keyOf(User $user, string $name = 'Deploy', array $abilities = ['profile:read', 'polls:vote'], array $attributes = []): NewAccessToken
    {
        $key = $user->createToken($name, $abilities, now()->addDays(60));
        $key->accessToken->forceFill($attributes)->save();

        return $key;
    }

    private function newest(User $user): PersonalAccessToken
    {
        return $user->tokens()->orderByDesc('id')->firstOrFail();
    }

    private function apiStatus(string $secret, string $uri = '/api/user'): int
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($secret)->getJson($uri)->status();
    }

    // --- Rotating -------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function rotating_with_no_grace_period_stops_the_old_key_and_gives_a_new_one_like_it(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user, 'Deploy', ['profile:read', 'polls:vote'], ['hourly_quota' => 10, 'daily_quota' => 100]);

        $response = $this->rotate($user, $old->accessToken, 0);

        $response->assertRedirect(route('settings.api-keys.index'))->assertSessionHas('success', 'API key rotated.');
        $secret = $response->getSession()->get('plain_text_token');

        $oldKey = $old->accessToken->fresh();
        $this->assertTrue($oldKey->revoked_at->equalTo(now()));
        $this->assertTrue($oldKey->rotated_at->equalTo(now()));

        $new = $this->newest($user);
        $this->assertNotSame($oldKey->id, $new->id);
        $this->assertSame('Deploy', $new->name);
        $this->assertSame(['profile:read', 'polls:vote'], $new->abilities);
        $this->assertSame(10, $new->hourly_quota);
        $this->assertSame(100, $new->daily_quota);
        $this->assertNull($new->rotated_at);
        $this->assertNull($new->revoked_at);

        $this->assertSame(401, $this->apiStatus($old->plainTextToken), 'the old key stopped at once');
        $this->assertSame(200, $this->apiStatus($secret), 'and the new one works');
    }

    #[Test]
    public function rotating_with_a_grace_period_lets_the_old_key_work_until_it_is_over(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);

        $secret = $this->rotate($user, $old->accessToken, 24)->getSession()->get('plain_text_token');

        $oldKey = $old->accessToken->fresh();
        $this->assertNull($oldKey->revoked_at, 'not revoked: it is working out its grace period');
        $this->assertTrue($oldKey->rotated_at->equalTo(now()));
        $this->assertTrue($oldKey->expires_at->equalTo(now()->addHours(24)));

        $this->assertSame(200, $this->apiStatus($old->plainTextToken));
        $this->assertSame(200, $this->apiStatus($secret));

        $this->travel(25)->hours();

        $this->assertSame(401, $this->apiStatus($old->plainTextToken), 'the grace period is over');
        $this->assertSame(200, $this->apiStatus($secret));
    }

    #[Test]
    public function a_key_that_is_to_end_sooner_than_the_grace_period_keeps_its_earlier_end(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user, attributes: ['expires_at' => now()->addHours(2)]);

        $this->rotate($user, $old->accessToken, 24);

        $this->assertTrue($old->accessToken->fresh()->expires_at->equalTo(now()->addHours(2)), 'rotating never gives the old key longer');
    }

    #[Test]
    public function the_new_key_lasts_as_long_as_the_old_one_was_made_to_from_now(): void
    {
        $user = User::factory()->create();
        // Made ten days ago to last thirty: twenty to go.
        $old = $this->keyOf($user, attributes: ['created_at' => now()->subDays(10), 'expires_at' => now()->addDays(20)]);

        $this->rotate($user, $old->accessToken, 0);

        $this->assertTrue($this->newest($user)->expires_at->equalTo(now()->addDays(30)));
    }

    #[Test]
    public function a_key_that_never_ends_gets_one_that_never_ends_where_the_site_allows_that(): void
    {
        config(['api.keys.allow_no_expiry' => true]);
        $user = User::factory()->create();
        $old = $this->keyOf($user, attributes: ['expires_at' => null]);

        $this->rotate($user, $old->accessToken, 0);

        $this->assertNull($this->newest($user)->expires_at);
    }

    #[Test]
    public function a_key_that_never_ends_gets_the_default_lifetime_where_the_site_does_not_allow_that(): void
    {
        config(['api.keys.allow_no_expiry' => false, 'api.keys.default_expiry_days' => 90]);
        $user = User::factory()->create();
        $old = $this->keyOf($user, attributes: ['expires_at' => null]);

        $this->rotate($user, $old->accessToken, 0);

        $this->assertTrue($this->newest($user)->expires_at->equalTo(now()->addDays(90)));
    }

    #[Test]
    public function a_key_with_full_access_is_rotated_to_one_with_full_access_and_not_more(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user, abilities: ['*']);

        $this->rotate($user, $old->accessToken, 0);

        $this->assertSame(['*'], $this->newest($user)->abilities);
    }

    #[Test]
    public function abilities_the_site_does_not_have_are_not_carried_over(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user, abilities: ['read', 'profile:read', 'update']);

        $this->rotate($user, $old->accessToken, 0);

        $this->assertSame(['profile:read'], $this->newest($user)->abilities);
    }

    #[Test]
    public function a_key_that_holds_nothing_the_site_has_is_not_rotated(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user, abilities: ['read', 'update']);

        $this->rotate($user, $old->accessToken, 0)->assertSessionHasErrors('key');

        $this->assertSame(1, $user->tokens()->count());
        $this->assertNull($old->accessToken->fresh()->rotated_at, 'nothing was changed');
        $this->assertNull($old->accessToken->fresh()->revoked_at);
    }

    #[Test]
    public function limits_staff_set_stay_staffs_on_the_new_key(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user, attributes: ['hourly_quota' => 5, 'daily_quota' => null, 'limits_set_by_staff_at' => now()->subDay()]);

        $this->rotate($user, $old->accessToken, 0);

        $new = $this->newest($user);
        $this->assertSame(5, $new->hourly_quota);
        $this->assertNull($new->daily_quota);
        $this->assertNotNull($new->limits_set_by_staff_at, 'rotating is no way round a limit staff put on a key');
    }

    #[Test]
    public function a_key_with_no_limits_gives_a_new_one_with_none(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);

        $this->rotate($user, $old->accessToken, 0);

        $new = $this->newest($user);
        $this->assertNull($new->hourly_quota);
        $this->assertNull($new->daily_quota);
        $this->assertNull($new->limits_set_by_staff_at);
    }

    // --- What cannot be rotated -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_key_that_no_longer_works_is_not_rotated(): void
    {
        $user = User::factory()->create();
        $revoked = $this->keyOf($user, 'Revoked', attributes: ['revoked_at' => now()->subDay()]);
        $expired = $this->keyOf($user, 'Expired', attributes: ['expires_at' => now()->subDay()]);

        $this->rotate($user, $revoked->accessToken, 0)->assertSessionHasErrors(['key' => 'This key no longer works, so there is nothing to replace. Make a new key instead.']);
        $this->rotate($user, $expired->accessToken, 0)->assertSessionHasErrors('key');

        $this->assertSame(2, $user->tokens()->count());
    }

    #[Test]
    public function a_key_that_is_being_replaced_is_not_rotated_again(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);

        $this->rotate($user, $old->accessToken, 24)->assertSessionHasNoErrors();
        $this->rotate($user, $old->accessToken, 24)->assertSessionHasErrors(['key' => 'This key has already been replaced, and is only working out its grace period.']);
        $this->rotate($user, $old->accessToken, 0)->assertSessionHasErrors('key');

        $this->assertSame(2, $user->tokens()->count(), 'one new key, not three');
    }

    #[Test]
    public function the_key_is_read_again_when_it_is_rotated_so_a_stale_copy_is_not_trusted(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);
        $stale = $old->accessToken->fresh();

        // Another request got there first.
        PersonalAccessToken::query()->whereKey($old->accessToken->id)->update(['revoked_at' => now()]);

        $this->expectException(ApiKeyRejected::class);

        try {
            app(ApiKeys::class)->rotate($user, $stale, 24);
        } finally {
            $this->assertSame(1, $user->tokens()->count());
        }
    }

    #[Test]
    public function nobody_rotates_somebody_elses_key(): void
    {
        $owner = User::factory()->create();
        $key = $this->keyOf($owner);

        $this->rotate(User::factory()->create(), $key->accessToken, 0)->assertNotFound();

        $this->assertSame(1, $owner->tokens()->count());
        $this->assertNull($key->accessToken->fresh()->rotated_at);
    }

    #[Test]
    public function a_key_that_is_not_there_is_not_found(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->actingAs(User::factory()->create())->post(route('settings.api-keys.rotate', 999999), ['grace_hours' => 0])->assertNotFound();
    }

    // --- The limit on keys ----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function at_the_limit_a_key_cannot_be_rotated_with_a_grace_period_as_there_is_no_room_for_both(): void
    {
        config(['api.keys.max_active' => 2]);
        $user = User::factory()->create();
        $first = $this->keyOf($user, 'First');
        $this->keyOf($user, 'Second');

        $this->rotate($user, $first->accessToken, 24)->assertSessionHasErrors('limit');

        $this->assertSame(2, $user->tokens()->count(), 'no new key');
        $this->assertNull($first->accessToken->fresh()->rotated_at, 'and the old one was left as it was');
        $this->assertTrue($first->accessToken->fresh()->expires_at->equalTo(now()->addDays(60)), 'and its end was not brought forward');
    }

    #[Test]
    public function at_the_limit_a_key_can_be_rotated_with_no_grace_period_as_it_takes_the_old_ones_place(): void
    {
        config(['api.keys.max_active' => 2]);
        $user = User::factory()->create();
        $first = $this->keyOf($user, 'First');
        $this->keyOf($user, 'Second');

        $this->rotate($user, $first->accessToken, 0)->assertSessionHasNoErrors();

        $this->assertSame(3, $user->tokens()->count());
        $this->assertSame(2, app(ApiKeys::class)->activeCount($user), 'still two they can use');
    }

    #[Test]
    public function a_key_that_is_being_replaced_still_counts_toward_the_limit_until_it_ends(): void
    {
        config(['api.keys.max_active' => 3]);
        $user = User::factory()->create();
        $first = $this->keyOf($user, 'First');
        $this->keyOf($user, 'Second');

        $this->rotate($user, $first->accessToken, 24)->assertSessionHasNoErrors();

        $this->assertSame(3, app(ApiKeys::class)->activeCount($user), 'the old one, the other, and the new one');

        // No way round the limit by rotating over and over: the places are all taken until the old key's end.
        $this->actingAs($user)->post(route('settings.api-keys.store'), ['name' => 'One more', 'scopes' => ['profile:read'], 'expires_in' => '30'])->assertSessionHasErrors('limit');

        $this->travel(25)->hours();

        $this->actingAs($user)->post(route('settings.api-keys.store'), ['name' => 'One more', 'scopes' => ['profile:read'], 'expires_in' => '30'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function rotating_over_and_over_cannot_pile_up_keys_past_the_limit(): void
    {
        config(['api.keys.max_active' => 3]);
        $user = User::factory()->create();
        $current = $this->keyOf($user)->accessToken;

        for ($i = 0; $i < 5; $i++) {
            $this->rotate($user, $current->fresh(), 24);
            $current = $this->newest($user);
        }

        $this->assertLessThanOrEqual(3, app(ApiKeys::class)->activeCount($user));
    }

    // --- Who may, and how often -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function where_the_site_asks_for_a_verified_address_an_unverified_person_does_not_rotate(): void
    {
        SystemSetting::set('email_verification_required', true);
        $user = User::factory()->unverified()->create();
        $key = $this->keyOf($user);

        $this->rotate($user, $key->accessToken, 0)->assertRedirect(route('verification.notice'));

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function the_service_will_not_rotate_for_somebody_who_has_not_verified_their_address_whatever_asks_it(): void
    {
        SystemSetting::set('email_verification_required', true);
        $user = User::factory()->unverified()->create();
        $key = $this->keyOf($user);

        try {
            app(ApiKeys::class)->rotate($user, $key->accessToken, 0);
            $this->fail('the key should not have been rotated');
        } catch (ApiKeyRejected $rejected) {
            $this->assertSame(['email'], array_keys($rejected->errors()));
        }

        $this->assertSame(1, $user->tokens()->count());
        $this->assertNull($key->accessToken->fresh()->rotated_at);
    }

    #[Test]
    public function rotating_shares_the_rate_limit_on_making_keys(): void
    {
        config(['api.keys.max_active' => 1000]);
        $user = User::factory()->create();
        $keys = array_map(fn (int $i) => $this->keyOf($user, "Key {$i}"), range(1, 6));

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($user, $keys[$i]->accessToken, 0)->assertRedirect(route('settings.api-keys.index'));
        }

        $this->attempt($user, $keys[5]->accessToken, 0)->assertStatus(429);
    }

    #[Test]
    public function the_grace_period_has_to_be_one_the_site_offers(): void
    {
        $user = User::factory()->create();
        $key = $this->keyOf($user);
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([5, -1, 999, 'soon', ''] as $value) {
            $this->actingAs($user)->post(route('settings.api-keys.rotate', $key->accessToken->id), ['grace_hours' => $value])->assertSessionHasErrors('grace_hours');
        }

        $this->actingAs($user)->post(route('settings.api-keys.rotate', $key->accessToken->id), [])->assertSessionHasErrors(['grace_hours' => 'Choose how long the old key should go on working.']);

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function a_site_chooses_the_grace_periods_it_offers(): void
    {
        config(['api.keys.rotation_grace_hours' => [6, 0, 6, -2], 'api.keys.default_rotation_grace_hours' => 12]);
        $user = User::factory()->create();
        $key = $this->keyOf($user);

        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('rotation', ['hours' => [0, 6], 'default' => 0]));

        $this->rotate($user, $key->accessToken, 6)->assertSessionHasNoErrors();
        $this->assertTrue($key->accessToken->fresh()->expires_at->equalTo(now()->addHours(6)));
    }

    #[Test]
    public function a_site_that_offers_no_grace_period_offers_only_stopping_the_old_key_at_once(): void
    {
        config(['api.keys.rotation_grace_hours' => [], 'api.keys.default_rotation_grace_hours' => 24]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('rotation', ['hours' => [0], 'default' => 0]));
    }

    // --- What is told and shown -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_owner_is_told_and_the_email_says_when_the_old_key_stops(): void
    {
        $user = User::factory()->create();
        $withGrace = $this->keyOf($user, 'With grace');
        $atOnce = $this->keyOf($user, 'At once');

        $this->rotate($user, $withGrace->accessToken, 24);
        $this->rotate($user, $atOnce->accessToken, 0);

        $sent = Notification::sent($user, ApiKeyCreated::class);
        $this->assertCount(2, $sent);

        $first = $sent->first();
        $this->assertSame(ApiKeyCreated::ROTATION, $first->how);
        $this->assertSame('With grace', $first->name);
        $this->assertSame('With grace', $first->replaced);
        $this->assertTrue($first->replacedUntil->equalTo(now()->addHours(24)));

        $this->assertNull($sent->last()->replacedUntil, 'stopped at once');
    }

    #[Test]
    public function the_new_secret_is_shown_once_and_the_old_key_is_listed_as_being_replaced(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);

        $this->rotate($user, $old->accessToken, 24)->assertRedirect();

        $this->get(route('settings.api-keys.index'))->assertInertia(fn (Assert $page) => $page
            ->where('flash.plain_text_token', fn ($token) => is_string($token) && str_contains($token, '|'))
            ->where('keys.0.being_replaced', false)
            ->where('keys.1.being_replaced', true)
            ->where('keys.1.rotated_at', fn ($at) => str_starts_with($at, '2026-10-15T12:00'))
            ->where('keys.1.status', 'active'));

        $this->get(route('settings.api-keys.index'))->assertInertia(fn (Assert $page) => $page->where('flash.plain_text_token', null));
    }

    #[Test]
    public function once_its_grace_period_is_over_the_old_key_is_expired_and_no_longer_being_replaced(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);

        $this->rotate($user, $old->accessToken, 1);
        $this->travel(2)->hours();

        $this->actingAs($user)->get(route('settings.api-keys.index'))->assertInertia(fn (Assert $page) => $page
            ->where('keys.1.status', 'expired')
            ->where('keys.1.being_replaced', false));
    }

    #[Test]
    public function a_key_that_is_being_replaced_can_still_be_revoked_and_removed(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);

        $this->rotate($user, $old->accessToken, 24);

        $this->post(route('settings.api-keys.revoke', $old->accessToken->id))->assertRedirect();
        $this->assertSame(401, $this->apiStatus($old->plainTextToken));

        $this->actingAs($user)->delete(route('settings.api-keys.destroy', $old->accessToken->id))->assertRedirect(route('settings.api-keys.index'));
        $this->assertModelMissing($old->accessToken);
    }
}
