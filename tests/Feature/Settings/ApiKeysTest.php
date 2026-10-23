<?php

namespace Tests\Feature\Settings;

use App\Models\PersonalAccessToken;
use App\Models\SystemSetting;
use App\Models\TokenLog;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Api\ApiKeyRejected;
use App\Support\Api\ApiKeys;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Settings → API keys: a person makes keys for themselves, limited to scopes and expiring, and takes them away (docs/api-keys.md).
 */
class ApiKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    }

    /**
     * @return array<string, mixed>
     */
    private function newKey(array $overrides = []): array
    {
        return [
            'name' => 'Deploy script',
            'scopes' => ['profile:read'],
            'expires_in' => '90',
            ...$overrides,
        ];
    }

    /** A request to make a key, with the rate limit on it (the tests of the limit use this one). */
    private function attempt(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->post(route('settings.api-keys.store'), $this->newKey($overrides));
    }

    /** Make a key, with the rate limit out of the way, so a test can make as many as it needs. */
    private function create(User $user, array $overrides = [])
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        return $this->attempt($user, $overrides);
    }

    private function keyOf(User $user, string $name, array $abilities = ['profile:read'], array $attributes = []): PersonalAccessToken
    {
        $key = $user->createToken($name, $abilities)->accessToken;
        $key->forceFill($attributes)->save();

        return $key;
    }

    // --- The page -------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_visitor_is_sent_to_sign_in(): void
    {
        $this->get(route('settings.api-keys.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_page_lists_only_the_persons_own_keys_newest_first(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user, 'Old', attributes: ['created_at' => now()->subDays(10)]);
        $new = $this->keyOf($user, 'New', attributes: ['created_at' => now()->subDay()]);
        $this->keyOf(User::factory()->create(), 'Somebody else');

        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/ApiKeys')
                ->has('keys', 2)
                ->where('keys.0.id', $new->id)
                ->where('keys.1.id', $old->id)
                ->where('keys.0.name', 'New'));
    }

    #[Test]
    public function a_key_is_active_expired_or_revoked(): void
    {
        $user = User::factory()->create();
        $this->keyOf($user, 'Live', attributes: ['expires_at' => now()->addDay(), 'created_at' => now()->subHours(1)]);
        $this->keyOf($user, 'Lapsed', attributes: ['expires_at' => now()->subDay(), 'created_at' => now()->subHours(2)]);
        $this->keyOf($user, 'Withdrawn', attributes: ['revoked_at' => now()->subHour(), 'created_at' => now()->subHours(3)]);
        $this->keyOf($user, 'Both', attributes: ['expires_at' => now()->subDay(), 'revoked_at' => now()->subHour(), 'created_at' => now()->subHours(4)]);

        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('keys.0.status', 'active')
                ->where('keys.1.status', 'expired')
                ->where('keys.2.status', 'revoked')
                ->where('keys.3.status', 'revoked', 'revoked wins over expired')
                ->where('limit.active', 1));
    }

    #[Test]
    public function a_key_shows_its_scopes_by_name_and_when_it_was_used_and_ends(): void
    {
        $user = User::factory()->create();
        $key = $this->keyOf($user, 'CI', ['profile:read', 'polls:vote'], [
            'last_used_at' => now()->subMinutes(5),
            'expires_at' => now()->addDays(30),
            'hourly_quota' => 100,
        ]);

        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('keys.0.id', $key->id)
                ->where('keys.0.full_access', false)
                ->where('keys.0.scopes', [
                    ['key' => 'profile:read', 'label' => 'Read your profile'],
                    ['key' => 'polls:vote', 'label' => 'Vote in polls'],
                ])
                ->where('keys.0.last_used_at', fn ($at) => str_starts_with($at, '2026-10-15T11:55'))
                ->where('keys.0.expires_at', fn ($at) => str_starts_with($at, '2026-11-14'))
                ->where('keys.0.hourly_quota', 100)
                ->where('keys.0.daily_quota', null));
    }

    #[Test]
    public function a_key_that_can_do_everything_says_so_and_one_from_before_scopes_is_shown_as_written(): void
    {
        $user = User::factory()->create();
        $this->keyOf($user, 'Everything', ['*'], ['created_at' => now()->subHour()]);
        $this->keyOf($user, 'Old style', ['read', 'update'], ['created_at' => now()->subHours(2)]);

        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('keys.0.full_access', true)
                ->where('keys.0.scopes', [])
                ->where('keys.1.full_access', false)
                ->where('keys.1.scopes', [['key' => 'read', 'label' => 'read'], ['key' => 'update', 'label' => 'update']]));
    }

    #[Test]
    public function calls_this_month_are_each_keys_own(): void
    {
        $user = User::factory()->create();
        $first = $this->keyOf($user, 'First', attributes: ['created_at' => now()->subHour()]);
        $second = $this->keyOf($user, 'Second', attributes: ['created_at' => now()->subHours(2)]);
        $unused = $this->keyOf($user, 'Unused', attributes: ['created_at' => now()->subHours(3)]);
        $stranger = $this->keyOf(User::factory()->create(), 'Stranger');

        $use = fn (User $owner, PersonalAccessToken $key, string $when, int $times) => UsageEvent::query()->insert(array_fill(0, $times, [
            'user_id' => $owner->id, 'meter' => 'api-calls', 'quantity' => 1, 'occurred_at' => $when, 'token_id' => $key->id, 'created_at' => now(),
        ]));
        $use($user, $first, '2026-10-02 08:00:00', 3);
        $use($user, $second, '2026-10-14 08:00:00', 1);
        $use($user, $first, '2026-10-01 00:00:00', 1); // the first moment of the month is in it
        $use($user, $first, '2026-11-01 00:00:00', 6); // and the first moment of the next is not
        $use($user, $first, '2026-09-30 23:00:00', 5);
        $use(User::factory()->create(), $stranger, '2026-10-03 08:00:00', 7);
        UsageEvent::query()->insert(['user_id' => $user->id, 'meter' => 'some-other-meter', 'quantity' => 9, 'occurred_at' => '2026-10-05 08:00:00', 'token_id' => $first->id, 'created_at' => now()]);

        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('keys.0.calls_this_month', 4)
                ->where('keys.1.calls_this_month', 1)
                ->where('keys.2.calls_this_month', 0)
                ->where('usage.used', 5, 'the account\'s own count, which these keys add up to')
                ->where('usage.meter', 'api-calls'));

        $this->assertNotNull($unused);
    }

    #[Test]
    public function the_page_says_what_can_be_chosen_and_how_many_keys_are_allowed(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('expiry', ['days' => [30, 90, 365], 'default' => 90, 'never' => true])
                ->where('limit', ['active' => 0, 'max' => 10])
                ->has('scopes', 6)
                ->where('scopes.0', ['key' => 'profile:read', 'label' => 'Read your profile', 'description' => 'Your nickname, email address and account details.']));
    }

    #[Test]
    public function a_scope_for_moderators_is_offered_only_to_them(): void
    {
        $member = User::factory()->create();
        $moderator = User::factory()->create();
        $moderator->assignRole(Role::findOrCreate('moderator'));

        $keys = fn (User $user) => collect(
            $this->actingAs($user)->get(route('settings.api-keys.index'))->viewData('page')['props']['scopes'],
        )->pluck('key')->all();

        $this->assertNotContains('forum:moderate', $keys($member));
        $this->assertContains('forum:moderate', $keys($moderator));
    }

    #[Test]
    public function a_new_secret_is_shown_once(): void
    {
        $user = User::factory()->create();

        $this->create($user)->assertRedirect(route('settings.api-keys.index'));

        $this->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('flash.plain_text_token', fn ($token) => is_string($token) && str_contains($token, '|')));

        $this->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('flash.plain_text_token', null));
    }

    // --- Making a key ---------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_person_makes_a_key_limited_to_scopes_that_ends(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['name' => ' Deploy script ', 'scopes' => ['profile:read', 'polls:vote'], 'expires_in' => '30'])
            ->assertSessionHasNoErrors();

        $key = $user->tokens()->sole();

        $this->assertSame('Deploy script', $key->name);
        $this->assertSame(['profile:read', 'polls:vote'], $key->abilities);
        $this->assertTrue($key->expires_at->equalTo(now()->addDays(30)));
        $this->assertNull($key->revoked_at);
    }

    #[Test]
    public function the_secret_is_not_kept(): void
    {
        $user = User::factory()->create();

        $secret = $this->create($user)->getSession()->get('plain_text_token');

        $stored = $user->tokens()->sole()->getRawOriginal('token');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString(explode('|', $secret)[1], $stored);
        $this->assertSame(hash('sha256', explode('|', $secret)[1]), $stored);
    }

    #[Test]
    public function the_key_works_for_what_it_was_limited_to_and_nothing_else(): void
    {
        $user = User::factory()->create();

        $secret = $this->create($user, ['scopes' => ['profile:read']])->getSession()->get('plain_text_token');
        $this->app['auth']->forgetGuards();

        $this->withToken($secret)->getJson('/api/user')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->postJson('/api/v1/polls/1/vote')->assertForbidden()->assertJson(['scope' => 'polls:vote']);
    }

    #[Test]
    public function a_key_can_be_made_to_last_until_it_is_revoked(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['expires_in' => 'never'])->assertSessionHasNoErrors();

        $this->assertNull($user->tokens()->sole()->expires_at);
    }

    #[Test]
    public function a_site_can_stop_keys_that_never_end(): void
    {
        config(['api.keys.allow_no_expiry' => false]);
        $user = User::factory()->create();

        $this->create($user, ['expires_in' => 'never'])->assertSessionHasErrors('expires_in');

        $this->assertSame(0, $user->tokens()->count());

        $this->get(route('settings.api-keys.index'))->assertInertia(fn (Assert $page) => $page->where('expiry.never', false));
    }

    #[Test]
    public function the_lifetimes_are_the_ones_the_site_offers(): void
    {
        config(['api.keys.expiry_days' => [7, 14], 'api.keys.default_expiry_days' => 90]);
        $user = User::factory()->create();

        $this->create($user, ['expires_in' => '90'])->assertSessionHasErrors('expires_in');
        $this->create($user, ['expires_in' => '14'])->assertSessionHasNoErrors();

        $this->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('expiry', ['days' => [7, 14], 'default' => 7, 'never' => true]));
    }

    #[Test]
    public function a_site_that_offers_no_lifetime_still_lets_a_key_be_made(): void
    {
        config(['api.keys.expiry_days' => [], 'api.keys.allow_no_expiry' => false]);

        $this->create(User::factory()->create(), ['expires_in' => 'never'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_made_up_lifetime_is_refused(): void
    {
        $user = User::factory()->create();

        foreach (['7', '0', '-1', 'forever', '', '90.5'] as $value) {
            $this->create($user, ['expires_in' => $value])->assertSessionHasErrors('expires_in');
        }

        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function a_key_needs_a_name_and_a_scope_and_only_scopes_the_site_has(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['name' => ''])->assertSessionHasErrors(['name' => 'Give the key a name, so you can tell it from your others.']);
        $this->create($user, ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name');
        $this->create($user, ['scopes' => []])->assertSessionHasErrors(['scopes' => 'Choose at least one thing the key can do.']);
        $this->create($user, ['scopes' => 'profile:read'])->assertSessionHasErrors('scopes');
        $this->create($user, ['scopes' => ['delete-everything']])->assertSessionHasErrors(['scopes.0' => 'That is not something a key can be limited to.']);
        $this->create($user, ['scopes' => ['profile:read', 'delete-everything']])->assertSessionHasErrors('scopes.1');

        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function the_scope_that_is_everything_is_not_one_a_person_can_pick(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['scopes' => ['*']])->assertSessionHasErrors('scopes.0');

        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function a_scope_meant_for_moderators_cannot_be_given_by_a_member(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['scopes' => ['forum:moderate']])->assertSessionHasErrors('scopes.0');

        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function a_scope_is_given_once_however_often_it_is_sent(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['scopes' => ['profile:read', 'profile:read', 'polls:vote']]);

        $this->assertSame(['profile:read', 'polls:vote'], $user->tokens()->sole()->abilities);
    }

    #[Test]
    public function a_person_cannot_make_more_keys_than_the_site_allows(): void
    {
        config(['api.keys.max_active' => 2]);
        $user = User::factory()->create();

        $this->create($user, ['name' => 'One'])->assertSessionHasNoErrors();
        $this->create($user, ['name' => 'Two'])->assertSessionHasNoErrors();
        $this->create($user, ['name' => 'Three'])->assertSessionHasErrors(['limit' => 'You already have 2 API keys you can use. Revoke one you no longer need, then make another.']);

        $this->assertSame(['One', 'Two'], $user->tokens()->orderBy('id')->pluck('name')->all());
    }

    #[Test]
    public function the_limit_counts_keys_that_can_still_be_used_and_is_each_persons_own(): void
    {
        config(['api.keys.max_active' => 1]);
        $user = User::factory()->create();
        $this->keyOf($user, 'Revoked', attributes: ['revoked_at' => now()]);
        $this->keyOf($user, 'Expired', attributes: ['expires_at' => now()->subMinute()]);
        $this->keyOf(User::factory()->create(), 'Somebody else');

        $this->create($user, ['name' => 'Fresh'])->assertSessionHasNoErrors();
        $this->create($user, ['name' => 'One too many'])->assertSessionHasErrors('limit');

        $this->assertSame(3, $user->tokens()->count());
    }

    #[Test]
    public function a_key_that_ends_today_still_counts_until_it_has_ended(): void
    {
        config(['api.keys.max_active' => 1]);
        $user = User::factory()->create();
        $this->keyOf($user, 'Ending', attributes: ['expires_at' => now()->addMinute()]);

        $this->create($user)->assertSessionHasErrors('limit');

        $this->travel(2)->minutes();

        $this->create($user)->assertSessionHasNoErrors();
    }

    #[Test]
    public function revoking_a_key_makes_room_for_another(): void
    {
        config(['api.keys.max_active' => 1]);
        $user = User::factory()->create();
        $key = $this->keyOf($user, 'Only');

        $this->create($user)->assertSessionHasErrors('limit');

        $this->post(route('settings.api-keys.revoke', $key->id));

        $this->create($user)->assertSessionHasNoErrors();
    }

    #[Test]
    public function making_keys_is_rate_limited(): void
    {
        config(['api.keys.max_active' => 1000]);
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($user, ['name' => "Key {$i}"])->assertRedirect(route('settings.api-keys.index'));
        }

        $this->attempt($user, ['name' => 'Sixth in a minute'])->assertStatus(429);

        $this->travel(61)->seconds();

        $this->attempt($user, ['name' => 'A minute later'])->assertRedirect(route('settings.api-keys.index'));
    }

    #[Test]
    public function making_keys_is_limited_for_each_person_not_for_each_place(): void
    {
        config(['api.keys.max_active' => 1000]);
        $busy = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($busy, ['name' => "Key {$i}"])->assertSessionHasNoErrors();
        }

        $this->attempt($busy, ['name' => 'One too many'])->assertStatus(429);

        // Somebody else, from the same address (an office, a phone network), is not held up by it.
        $this->attempt($other)->assertRedirect(route('settings.api-keys.index'));
    }

    #[Test]
    public function making_keys_is_limited_by_the_hour_as_well(): void
    {
        config(['api.keys.max_active' => 1000]);
        $user = User::factory()->create();

        for ($round = 0; $round < 4; $round++) {
            for ($i = 0; $i < 5; $i++) {
                $this->attempt($user, ['name' => "Key {$round}-{$i}"])->assertRedirect(route('settings.api-keys.index'));
            }

            $this->travel(61)->seconds();
        }

        $this->attempt($user, ['name' => 'The twenty-first'])->assertStatus(429);
    }

    #[Test]
    public function where_the_site_asks_for_a_verified_address_an_unverified_person_makes_no_key(): void
    {
        SystemSetting::set('email_verification_required', true);
        $user = User::factory()->unverified()->create();

        $this->create($user)->assertRedirect(route('verification.notice'));

        $this->assertSame(0, $user->tokens()->count());

        $this->get(route('settings.api-keys.index'))->assertOk();
    }

    #[Test]
    public function a_verified_person_makes_a_key_where_the_site_asks_for_one(): void
    {
        SystemSetting::set('email_verification_required', true);

        $this->create(User::factory()->create())->assertSessionHasNoErrors();
    }

    // --- Taking a key away ----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function revoking_a_key_stops_it_working_and_keeps_it_on_the_list(): void
    {
        $user = User::factory()->create();
        $secret = $this->create($user)->getSession()->get('plain_text_token');
        $key = $user->tokens()->sole();

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson('/api/user')->assertOk();

        $this->actingAs($user)->post(route('settings.api-keys.revoke', $key->id))
            ->assertRedirect(route('settings.api-keys.index'))
            ->assertSessionHas('success', 'API key revoked.');

        $this->assertTrue($key->fresh()->revoked_at->equalTo(now()));

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson('/api/user')->assertUnauthorized();

        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->has('keys', 1)->where('keys.0.status', 'revoked'));
    }

    #[Test]
    public function revoking_a_key_leaves_the_persons_others_working(): void
    {
        $user = User::factory()->create();
        $revoked = $this->keyOf($user, 'Revoked');
        $other = $user->createToken('Other', ['profile:read']);

        $this->actingAs($user)->post(route('settings.api-keys.revoke', $revoked->id));

        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/user')->assertOk();
    }

    #[Test]
    public function revoking_a_key_twice_keeps_the_first_time(): void
    {
        $user = User::factory()->create();
        $key = $this->keyOf($user, 'Once');

        $this->actingAs($user)->post(route('settings.api-keys.revoke', $key->id));
        $first = $key->fresh()->revoked_at;

        $this->travel(1)->hour();
        $this->post(route('settings.api-keys.revoke', $key->id))->assertRedirect();

        $this->assertTrue($key->fresh()->revoked_at->equalTo($first));
    }

    #[Test]
    public function nobody_revokes_or_removes_somebody_elses_key(): void
    {
        $owner = User::factory()->create();
        $key = $this->keyOf($owner, 'Theirs');
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('settings.api-keys.revoke', $key->id))->assertNotFound();
        $this->delete(route('settings.api-keys.destroy', $key->id))->assertNotFound();

        $this->assertNull($key->fresh()->revoked_at);
        $this->assertModelExists($key);
    }

    #[Test]
    public function a_key_that_is_not_there_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.api-keys.revoke', 999999))->assertNotFound();
        $this->delete(route('settings.api-keys.destroy', 999999))->assertNotFound();
    }

    #[Test]
    public function removing_a_key_deletes_it_and_its_log_but_not_what_the_account_used(): void
    {
        $user = User::factory()->create();
        $key = $this->keyOf($user, 'Gone', attributes: ['revoked_at' => now()]);
        $kept = $this->keyOf($user, 'Kept');
        TokenLog::query()->create([
            'personal_access_token_id' => $key->id, 'token_name' => 'Gone', 'route' => '/api/user', 'method' => 'GET', 'status' => 'success', 'http_status' => 200,
        ]);
        UsageEvent::query()->insert(['user_id' => $user->id, 'meter' => 'api-calls', 'quantity' => 1, 'occurred_at' => now(), 'token_id' => $key->id, 'created_at' => now()]);

        $this->actingAs($user)->delete(route('settings.api-keys.destroy', $key->id))
            ->assertRedirect(route('settings.api-keys.index'))
            ->assertSessionHas('success', 'API key removed.');

        $this->assertModelMissing($key);
        $this->assertModelExists($kept);
        $this->assertSame(0, TokenLog::query()->count());
        $this->assertSame(1, UsageEvent::query()->count(), 'the account used it all the same');
    }

    #[Test]
    public function removing_a_key_that_still_works_stops_it_too(): void
    {
        $user = User::factory()->create();
        $key = $user->createToken('Live', ['profile:read']);

        $this->actingAs($user)->delete(route('settings.api-keys.destroy', $key->accessToken->id));

        $this->app['auth']->forgetGuards();
        $this->withToken($key->plainTextToken)->getJson('/api/user')->assertUnauthorized();
    }

    #[Test]
    public function using_a_key_shows_when_it_was_last_used(): void
    {
        $user = User::factory()->create();
        $key = $user->createToken('Live', ['profile:read']);

        $this->withToken($key->plainTextToken)->getJson('/api/user')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('keys.0.last_used_at', fn ($at) => str_starts_with($at, '2026-10-15T12:00')));
    }

    #[Test]
    public function keys_made_in_the_same_second_are_listed_newest_first(): void
    {
        $user = User::factory()->create();
        $first = $this->keyOf($user, 'First', attributes: ['created_at' => now()]);
        $second = $this->keyOf($user, 'Second', attributes: ['created_at' => now()]);

        $this->actingAs($user)->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('keys.0.id', $second->id)->where('keys.1.id', $first->id));
    }

    #[Test]
    public function the_service_gives_a_scope_once_and_refuses_one_the_site_does_not_have(): void
    {
        $user = User::factory()->create();

        $key = app(ApiKeys::class)->create($user, 'Service', ['profile:read', 'polls:vote', 'profile:read'], 30);

        $this->assertSame(['profile:read', 'polls:vote'], $key->accessToken->abilities);
        $this->assertTrue($key->accessToken->expires_at->equalTo(now()->addDays(30)));

        $this->expectException(InvalidArgumentException::class);
        app(ApiKeys::class)->create($user, 'Typo', ['profile:read', 'profile-read'], null);
    }

    #[Test]
    public function the_service_accepts_the_scope_that_is_everything_though_the_page_never_offers_it(): void
    {
        $key = app(ApiKeys::class)->create(User::factory()->create(), 'Everything', ['*'], 30);

        $this->assertSame(['*'], $key->accessToken->abilities);
    }

    #[Test]
    public function the_service_makes_no_key_for_somebody_who_has_not_verified_their_address_where_the_site_asks_for_it(): void
    {
        SystemSetting::set('email_verification_required', true);
        $user = User::factory()->unverified()->create();

        try {
            app(ApiKeys::class)->create($user, 'Early', ['profile:read'], 30);
            $this->fail('the key should have been refused');
        } catch (ApiKeyRejected $rejected) {
            $this->assertSame(['email'], array_keys($rejected->errors()));
        }

        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function the_service_gives_the_default_lifetime_instead_of_none_where_a_key_must_end(): void
    {
        config(['api.keys.allow_no_expiry' => false, 'api.keys.default_expiry_days' => 90]);

        $key = app(ApiKeys::class)->create(User::factory()->create(), 'Must end', ['profile:read'], null);

        $this->assertTrue($key->accessToken->expires_at->equalTo(now()->addDays(90)));
    }

    #[Test]
    public function the_service_still_lets_a_key_last_where_the_site_offers_no_lifetime_at_all(): void
    {
        config(['api.keys.allow_no_expiry' => false, 'api.keys.expiry_days' => []]);

        $key = app(ApiKeys::class)->create(User::factory()->create(), 'No choice', ['profile:read'], null);

        $this->assertNull($key->accessToken->expires_at);
    }

    #[Test]
    public function the_service_makes_a_key_that_never_ends_when_given_no_days_and_makes_no_key_when_it_refuses(): void
    {
        config(['api.keys.max_active' => 1]);
        $user = User::factory()->create();

        $this->assertNull(app(ApiKeys::class)->create($user, 'Forever', ['profile:read'], null)->accessToken->expires_at);

        try {
            app(ApiKeys::class)->create($user, 'Second', ['profile:read'], 30);
            $this->fail('the second key should have been refused');
        } catch (ApiKeyRejected $rejected) {
            $this->assertSame(['limit'], array_keys($rejected->errors()));
        }

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function a_limit_of_nothing_still_lets_a_person_have_one_key(): void
    {
        config(['api.keys.max_active' => 0]);
        $user = User::factory()->create();

        $this->create($user, ['name' => 'First'])->assertSessionHasNoErrors();
        $this->create($user, ['name' => 'Second'])->assertSessionHasErrors(['limit' => 'You already have 1 API key you can use. Revoke one you no longer need, then make another.']);

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function the_lifetimes_offered_are_in_order_and_each_one_once_and_never_nothing_or_less(): void
    {
        config(['api.keys.expiry_days' => [365, 0, 30, -5, 30, 90]]);

        $this->actingAs(User::factory()->create())->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('expiry.days', [30, 90, 365]));
    }
}
