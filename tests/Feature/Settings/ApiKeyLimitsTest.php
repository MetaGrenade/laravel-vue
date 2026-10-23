<?php

namespace Tests\Feature\Settings;

use App\Models\PersonalAccessToken;
use App\Models\TokenLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An owner can limit how many calls one of their keys makes an hour and a day, unless staff have set its limits (docs/api-keys.md).
 */
class ApiKeyLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function create(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->post(route('settings.api-keys.store'), [
            'name' => 'Script', 'scopes' => ['profile:read'], 'expires_in' => '90', ...$overrides,
        ]);
    }

    private function limit(User $user, PersonalAccessToken $key, array $limits)
    {
        return $this->actingAs($user)->patch(route('settings.api-keys.limits', $key->id), $limits);
    }

    private function keyOf(User $user, array $attributes = []): PersonalAccessToken
    {
        $key = $user->createToken('Key', ['profile:read'])->accessToken;
        $key->forceFill($attributes)->save();

        return $key;
    }

    // --- Setting them when a key is made --------------------------------------------------------------------------------------------------

    #[Test]
    public function a_key_can_be_made_with_limits(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['hourly_quota' => '100', 'daily_quota' => '1000'])->assertSessionHasNoErrors();

        $key = $user->tokens()->sole();
        $this->assertSame(100, $key->hourly_quota);
        $this->assertSame(1000, $key->daily_quota);
        $this->assertNull($key->limits_set_by_staff_at, 'they are the owner\'s own');
    }

    #[Test]
    public function a_key_can_have_just_one_of_them_or_none(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['name' => 'Hourly', 'hourly_quota' => 50])->assertSessionHasNoErrors();
        $this->create($user, ['name' => 'Daily', 'daily_quota' => 500])->assertSessionHasNoErrors();
        $this->create($user, ['name' => 'Neither', 'hourly_quota' => '', 'daily_quota' => null])->assertSessionHasNoErrors();

        $keys = $user->tokens()->orderBy('id')->get();
        $this->assertSame([50, null], [$keys[0]->hourly_quota, $keys[0]->daily_quota]);
        $this->assertSame([null, 500], [$keys[1]->hourly_quota, $keys[1]->daily_quota]);
        $this->assertSame([null, null], [$keys[2]->hourly_quota, $keys[2]->daily_quota]);
        $this->assertNull($keys[2]->limits_set_by_staff_at);
    }

    #[Test]
    public function a_limit_must_be_a_whole_number_of_calls_between_one_and_ten_million(): void
    {
        $user = User::factory()->create();

        foreach (['0', '-5', 'many', '1.5', '10000001'] as $value) {
            $this->create($user, ['hourly_quota' => $value])->assertSessionHasErrors('hourly_quota');
            $this->create($user, ['daily_quota' => $value])->assertSessionHasErrors('daily_quota');
        }

        $this->create($user, ['hourly_quota' => 'many'])->assertSessionHasErrors(['hourly_quota' => 'Enter a whole number of calls, or leave it empty for no limit.']);
        $this->create($user, ['hourly_quota' => '0'])->assertSessionHasErrors(['hourly_quota' => 'A limit must be at least 1 call. Leave it empty for no limit.']);
        $this->create($user, ['daily_quota' => '10000001'])->assertSessionHasErrors(['daily_quota' => 'A limit cannot be more than 10,000,000 calls.']);

        $this->assertSame(0, $user->tokens()->count());

        $this->create($user, ['hourly_quota' => '1', 'daily_quota' => '10000000'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_limit_the_owner_sets_is_kept_to(): void
    {
        $user = User::factory()->create();

        $secret = $this->create($user, ['hourly_quota' => 2])->getSession()->get('plain_text_token');

        foreach ([200, 200] as $expected) {
            $this->app['auth']->forgetGuards();
            $this->withToken($secret)->getJson('/api/user')->assertStatus($expected);
        }

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson('/api/user')
            ->assertStatus(429)
            ->assertJson(['message' => 'API quota exceeded for this token.', 'limit' => 2, 'period' => 'hour']);

        $this->assertSame(2, TokenLog::query()->count());
    }

    // --- Changing them --------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function an_owner_changes_the_limits_on_a_key_and_takes_them_off(): void
    {
        $user = User::factory()->create();
        $key = $this->keyOf($user);

        $this->limit($user, $key, ['hourly_quota' => 20, 'daily_quota' => 200])
            ->assertRedirect(route('settings.api-keys.index'))
            ->assertSessionHas('success', 'API key limits saved.');
        $this->assertSame([20, 200], [$key->fresh()->hourly_quota, $key->fresh()->daily_quota]);

        $this->limit($user, $key, ['hourly_quota' => 30, 'daily_quota' => null]);
        $this->assertSame([30, null], [$key->fresh()->hourly_quota, $key->fresh()->daily_quota]);

        $this->limit($user, $key, ['hourly_quota' => '', 'daily_quota' => '']);
        $this->assertSame([null, null], [$key->fresh()->hourly_quota, $key->fresh()->daily_quota]);

        $this->assertNull($key->fresh()->limits_set_by_staff_at, 'still the owner\'s');
    }

    #[Test]
    public function changed_limits_are_what_the_key_is_kept_to(): void
    {
        $user = User::factory()->create();
        $key = $user->createToken('Key', ['profile:read']);

        $this->limit($user, $key->accessToken, ['daily_quota' => 1])->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards();
        $this->withToken($key->plainTextToken)->getJson('/api/user')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($key->plainTextToken)->getJson('/api/user')->assertStatus(429)->assertJson(['period' => 'day']);
    }

    #[Test]
    public function a_bad_limit_changes_nothing(): void
    {
        $user = User::factory()->create();
        $key = $this->keyOf($user, ['hourly_quota' => 10, 'daily_quota' => 100]);

        $this->limit($user, $key, ['hourly_quota' => 0, 'daily_quota' => 'lots'])->assertSessionHasErrors(['hourly_quota', 'daily_quota']);

        $this->assertSame([10, 100], [$key->fresh()->hourly_quota, $key->fresh()->daily_quota]);
    }

    #[Test]
    public function limits_staff_set_are_not_the_owners_to_change_or_take_off(): void
    {
        $user = User::factory()->create();
        $key = $this->keyOf($user, ['hourly_quota' => 10, 'daily_quota' => 100, 'limits_set_by_staff_at' => now()]);

        $this->limit($user, $key, ['hourly_quota' => 1000, 'daily_quota' => 10000])->assertSessionHasErrors(['limits' => 'Staff set the limits on this key, so you cannot change them.']);
        $this->limit($user, $key, ['hourly_quota' => null, 'daily_quota' => null])->assertSessionHasErrors('limits');

        $this->assertSame([10, 100], [$key->fresh()->hourly_quota, $key->fresh()->daily_quota]);
    }

    #[Test]
    public function the_page_says_which_keys_have_limits_from_staff_and_how_far_a_limit_can_go(): void
    {
        $user = User::factory()->create();
        $this->keyOf($user, ['hourly_quota' => 10, 'limits_set_by_staff_at' => now(), 'created_at' => now()->subDay()]);
        $this->keyOf($user, ['hourly_quota' => 20, 'created_at' => now()->subDays(2)]);

        $this->actingAs($user)->get(route('settings.api-keys.index'))->assertInertia(fn (Assert $page) => $page
            ->where('keys.0.limits_from_staff', true)
            ->where('keys.0.hourly_quota', 10)
            ->where('keys.1.limits_from_staff', false)
            ->where('keys.1.hourly_quota', 20)
            ->where('max_calls', 10000000));
    }

    #[Test]
    public function nobody_limits_somebody_elses_key(): void
    {
        $owner = User::factory()->create();
        $key = $this->keyOf($owner);

        $this->limit(User::factory()->create(), $key, ['hourly_quota' => 1])->assertNotFound();

        $this->assertNull($key->fresh()->hourly_quota);
    }

    #[Test]
    public function a_key_that_is_not_there_is_not_found_and_a_visitor_is_sent_to_sign_in(): void
    {
        $this->actingAs(User::factory()->create())->patch(route('settings.api-keys.limits', 999999), ['hourly_quota' => 1])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->patch(route('settings.api-keys.limits', 1), ['hourly_quota' => 1])->assertRedirect(route('login'));
    }

    // --- Staff ----------------------------------------------------------------------------------------------------------------------------

    private function signInAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function form(User $owner, array $overrides = []): array
    {
        return [
            'name' => 'Key', 'user_id' => $owner->id, 'abilities' => ['profile:read'], 'expires_at' => null,
            'hourly_quota' => null, 'daily_quota' => null, ...$overrides,
        ];
    }

    #[Test]
    public function a_key_staff_make_with_limits_has_limits_that_are_theirs(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();

        $this->post(route('acp.tokens.store'), $this->form($owner, ['hourly_quota' => 10]))->assertSessionHasNoErrors();

        $key = $owner->tokens()->sole();
        $this->assertNotNull($key->limits_set_by_staff_at);
        $this->assertTrue($key->hasLimitsFromStaff());

        $this->limit($owner, $key, ['hourly_quota' => 5000])->assertSessionHasErrors('limits');
    }

    #[Test]
    public function a_key_staff_make_without_limits_is_still_the_owners_to_limit(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();

        $this->post(route('acp.tokens.store'), $this->form($owner))->assertSessionHasNoErrors();

        $key = $owner->tokens()->sole();
        $this->assertNull($key->limits_set_by_staff_at);

        $this->limit($owner, $key, ['hourly_quota' => 5])->assertSessionHasNoErrors();
        $this->assertSame(5, $key->fresh()->hourly_quota);
    }

    #[Test]
    public function staff_changing_a_limit_makes_it_theirs(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();
        $key = $this->keyOf($owner, ['hourly_quota' => 10]);

        $this->put(route('acp.tokens.update', $key->id), $this->form($owner, ['hourly_quota' => 20]))->assertSessionHasNoErrors();

        $this->assertSame(20, $key->fresh()->hourly_quota);
        $this->assertNotNull($key->fresh()->limits_set_by_staff_at);
    }

    #[Test]
    public function staff_adding_a_limit_to_a_key_without_one_makes_it_theirs(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();
        $key = $this->keyOf($owner);

        $this->put(route('acp.tokens.update', $key->id), $this->form($owner, ['daily_quota' => 500]))->assertSessionHasNoErrors();

        $this->assertNotNull($key->fresh()->limits_set_by_staff_at);
    }

    #[Test]
    public function staff_saving_a_key_without_changing_its_limits_does_not_take_them_from_the_owner(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();
        $key = $this->keyOf($owner, ['hourly_quota' => 10, 'daily_quota' => 100]);

        // Renaming it: the form sends the limits back as they were.
        $this->put(route('acp.tokens.update', $key->id), $this->form($owner, ['name' => 'Renamed', 'hourly_quota' => 10, 'daily_quota' => 100]))->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $key->fresh()->name);
        $this->assertNull($key->fresh()->limits_set_by_staff_at, 'the limits are still the owner\'s');
    }

    #[Test]
    public function staff_saving_a_key_with_limits_of_their_own_leaves_them_theirs(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00')); // a moment with no fraction of a second: the database keeps whole seconds
        $this->signInAdmin();
        $owner = User::factory()->create();
        $key = $this->keyOf($owner, ['hourly_quota' => 10, 'limits_set_by_staff_at' => now()->subWeek()]);

        $this->put(route('acp.tokens.update', $key->id), $this->form($owner, ['name' => 'Renamed', 'hourly_quota' => 10]))->assertSessionHasNoErrors();

        $this->assertTrue($key->fresh()->limits_set_by_staff_at->equalTo(now()->subWeek()), 'the date they were set is not moved');
    }

    #[Test]
    public function staff_taking_both_limits_off_hands_the_key_back_to_its_owner(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();
        $key = $this->keyOf($owner, ['hourly_quota' => 10, 'daily_quota' => 100, 'limits_set_by_staff_at' => now()]);

        $this->put(route('acp.tokens.update', $key->id), $this->form($owner, ['hourly_quota' => null, 'daily_quota' => null]))->assertSessionHasNoErrors();

        $fresh = $key->fresh();
        $this->assertNull($fresh->hourly_quota);
        $this->assertNull($fresh->daily_quota);
        $this->assertNull($fresh->limits_set_by_staff_at);

        $this->limit($owner, $key, ['hourly_quota' => 7])->assertSessionHasNoErrors();
    }
}
