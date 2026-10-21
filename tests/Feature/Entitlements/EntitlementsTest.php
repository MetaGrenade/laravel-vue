<?php

namespace Tests\Feature\Entitlements;

use App\Enums\EntitlementType;
use App\Http\Middleware\EnsureEntitled;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\EntitlementSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What an owner holds, whichever provider gave it: a feature that is on, a limit that adds up. Not taken from a cache, so
 * what it says is never older than the last grant.
 */
class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private function grant(User $owner, string $key = 'reports', ?int $value = null, array $state = [], bool $limit = false): UserEntitlement
    {
        $entitlement = Entitlement::query()->where('key', $key)->first()
            ?? ($limit ? Entitlement::factory()->limit() : Entitlement::factory())->create(['key' => $key, 'label' => ucfirst($key)]);

        return UserEntitlement::factory()->create([
            'entitlement_id' => $entitlement->id,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'value' => $value,
            ...$state,
        ]);
    }

    // --- The set -------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_set_says_what_is_on_and_how_much_of_a_limit_there_is(): void
    {
        $set = new EntitlementSet(['reports'], ['team_seats' => 5, 'empty' => 0]);

        $this->assertTrue($set->has('reports'));
        $this->assertTrue($set->has('team_seats'), 'a limit above nothing counts as held');
        $this->assertFalse($set->has('empty'), 'a limit of nothing does not');
        $this->assertFalse($set->has('unknown'));
        $this->assertSame(5, $set->limit('team_seats'));
        $this->assertSame(0, $set->limit('reports'), 'a feature is not a number');
        $this->assertSame(0, $set->limit('unknown'));
        $this->assertFalse($set->isEmpty());
        $this->assertTrue((new EntitlementSet)->isEmpty());
    }

    #[Test]
    public function sets_merge_by_switching_features_on_and_adding_limits_up(): void
    {
        $merged = (new EntitlementSet(['a', 'b'], ['seats' => 5]))->merge(new EntitlementSet(['b', 'c'], ['seats' => 3, 'projects' => 2]));

        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $merged->features());
        $this->assertSame(['seats' => 8, 'projects' => 2], $merged->limits());
        $this->assertSame(['features' => $merged->features(), 'limits' => ['seats' => 8, 'projects' => 2]], $merged->toArray());
    }

    // --- Reading what an owner holds ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_owner_holds_the_features_and_the_limits_they_were_given(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'reports');
        $this->grant($user, 'team_seats', 5, limit: true);
        $this->grant($user, 'team_seats', 3);

        $set = app(Entitlements::class)->for($user);

        $this->assertSame(['reports'], $set->features());
        $this->assertSame(['team_seats' => 8], $set->limits(), 'two grants of a limit add up');
    }

    #[Test]
    public function a_grant_that_was_taken_back_or_has_run_out_does_not_count(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'taken_back')->forceFill(['revoked_at' => now(), 'revoked_reason' => UserEntitlement::BY_REFUND])->save();
        $this->grant($user, 'ran_out', state: ['expires_at' => now()->subMinute()]);
        $this->grant($user, 'still_running', state: ['expires_at' => now()->addMonth()]);
        $this->grant($user, 'forever');

        $set = app(Entitlements::class)->for($user);

        $this->assertEqualsCanonicalizing(['still_running', 'forever'], $set->features());
    }

    #[Test]
    public function an_owner_holds_nothing_that_was_given_to_somebody_else_or_to_nobody(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->grant($other, 'theirs');
        UserEntitlement::factory()->create(['entitlement_id' => Entitlement::factory()->create(['key' => 'nobodys'])->id]);

        $this->assertTrue(app(Entitlements::class)->for($user)->isEmpty());
        $this->assertSame(['theirs'], app(Entitlements::class)->for($other)->features());
    }

    #[Test]
    public function what_a_user_holds_is_never_older_than_the_last_grant(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->canUse('reports'));

        $grant = $this->grant($user, 'reports');
        $this->assertTrue($user->canUse('reports'), 'nothing is remembered between asks');

        $grant->forceFill(['revoked_at' => now(), 'revoked_reason' => 'staff'])->save();
        $this->assertFalse($user->canUse('reports'));
    }

    #[Test]
    public function a_user_has_helpers_for_asking(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'reports');
        $this->grant($user, 'team_seats', 4, limit: true);

        $this->assertTrue($user->canUse('reports'));
        $this->assertTrue($user->canUse('team_seats'));
        $this->assertFalse($user->canUse('nope'));
        $this->assertSame(4, $user->limitOf('team_seats'));
        $this->assertSame(0, $user->limitOf('nope'));
        $this->assertSame(['reports'], $user->entitlementSet()->features());
    }

    #[Test]
    public function what_a_user_holds_is_described_once_each_with_limits_added_up_and_nothing_taken_back(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'zeta');
        $this->grant($user, 'team_seats', 5, limit: true);
        $this->grant($user, 'team_seats', 3);
        $this->grant($user, 'gone')->forceFill(['revoked_at' => now(), 'revoked_reason' => 'staff'])->save();

        $held = app(Entitlements::class)->held($user);

        $this->assertSame(['Team_seats', 'Zeta'], array_column($held, 'label'), 'by name');
        $this->assertSame(8, $held[0]['value']);
        $this->assertSame('limit', $held[0]['type']);
        $this->assertNull($held[1]['value']);
        $this->assertSame(EntitlementType::Feature->value, $held[1]['type']);
    }

    // --- The route middleware ------------------------------------------------------------------------------------------------------

    private function gated(string ...$keys): string
    {
        $path = '/_entitled/'.implode('-', $keys ?: ['none']);

        Route::middleware(['web', 'entitled'.($keys ? ':'.implode(',', $keys) : '')])->get($path, fn () => 'let in');

        return $path;
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->get($this->gated('reports'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_user_without_it_is_refused_and_a_user_with_it_is_let_in(): void
    {
        $user = User::factory()->create();
        $path = $this->gated('reports');

        $this->actingAs($user)->get($path)->assertForbidden();

        $this->grant($user, 'reports');
        $this->actingAs($user)->get($path)->assertOk()->assertSee('let in');
    }

    #[Test]
    public function a_limit_lets_in_while_it_is_above_nothing(): void
    {
        $user = User::factory()->create();
        $path = $this->gated('team_seats');
        $grant = $this->grant($user, 'team_seats', 2, limit: true);

        $this->actingAs($user)->get($path)->assertOk();

        $grant->forceFill(['revoked_at' => now(), 'revoked_reason' => 'staff'])->save();
        $this->actingAs($user)->get($path)->assertForbidden();
    }

    #[Test]
    public function every_entitlement_named_is_needed(): void
    {
        $user = User::factory()->create();
        $path = $this->gated('reports', 'exports');
        $this->grant($user, 'reports');

        $this->actingAs($user)->get($path)->assertForbidden();

        $this->grant($user, 'exports');
        $this->actingAs($user)->get($path)->assertOk();
    }

    #[Test]
    public function naming_no_entitlement_fails_loudly_rather_than_letting_everybody_in(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EnsureEntitled)->handle(request(), fn () => response('let in'));
    }

    // --- The page ------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_page_is_given_what_the_signed_in_user_holds(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'reports');
        $this->grant($user, 'team_seats', 5, limit: true);

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.entitlements.features', ['reports'])
            ->where('auth.entitlements.limits.team_seats', 5));
    }

    #[Test]
    public function a_user_who_holds_nothing_and_a_guest_are_given_nothing(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.entitlements.features', [])
            ->where('auth.entitlements.limits', []));

        $this->app['auth']->forgetGuards();

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.entitlements.features', [])
            ->where('auth.entitlements.limits', []));
    }

    // --- The keys ------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_key_is_lower_case_words_joined_by_dots_dashes_or_underscores(): void
    {
        foreach (['reports', 'team.seats', 'api-rate_limit', 'a1', 'v2.exports'] as $good) {
            $this->assertSame(1, preg_match(Entitlement::KEY_PATTERN, $good), $good);
        }

        foreach (['', 'Reports', '1reports', 'team seats', 'team..seats', '.reports', 'reports.', 'team-', 'a_', 'réports'] as $bad) {
            $this->assertSame(0, preg_match(Entitlement::KEY_PATTERN, $bad), "'{$bad}' should be refused");
        }
    }
}
