<?php

namespace Tests\Feature\Entitlements;

use App\Models\Entitlement;
use App\Models\Product;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a user's subscription plans give them. Nothing is written when someone subscribes: the plans of the subscriptions that
 * are live right now are what they hold, so a change to a plan or a subscription reaches them the next time anything asks.
 */
class PlanEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private int $subscriptions = 0;

    /**
     * A plan that gives the entitlements named (key => amount for a limit, key => null for a feature).
     *
     * @param  array<string, int|null>  $gives
     */
    private function plan(array $gives, array $state = []): SubscriptionPlan
    {
        $plan = SubscriptionPlan::factory()->create($state);

        $this->gives($plan, $gives);

        return $plan;
    }

    /**
     * @param  array<string, int|null>  $gives
     */
    private function gives(SubscriptionPlan $plan, array $gives): void
    {
        foreach ($gives as $key => $value) {
            $entitlement = Entitlement::query()->where('key', $key)->first()
                ?? ($value === null ? Entitlement::factory() : Entitlement::factory()->limit())->create(['key' => $key, 'label' => ucfirst($key)]);

            SubscriptionPlanEntitlement::create([
                'subscription_plan_id' => $plan->id,
                'entitlement_id' => $entitlement->id,
                'value' => $value,
            ]);
        }
    }

    private function subscribe(User $user, SubscriptionPlan $plan, array $state = []): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => config('billing.subscription_name', 'default'),
            'stripe_id' => 'sub_plan_'.(++$this->subscriptions),
            'stripe_status' => 'active',
            'stripe_price' => $plan->stripe_price_id,
            'quantity' => 1,
            ...$state,
        ]);
    }

    // --- What a live subscription gives ---------------------------------------------------------------------------------------

    #[Test]
    public function a_user_on_a_plan_holds_the_features_and_limits_it_gives(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, $this->plan(['reports' => null, 'projects' => 10]));

        $this->assertTrue($user->canUse('reports'));
        $this->assertTrue($user->canUse('projects'));
        $this->assertSame(10, $user->limitOf('projects'));
        $this->assertFalse($user->canUse('something-else'));
    }

    #[Test]
    public function a_user_with_no_subscription_holds_nothing_from_a_plan_and_nobody_holds_what_another_is_on(): void
    {
        $plan = $this->plan(['reports' => null]);
        $this->subscribe(User::factory()->create(), $plan);

        $this->assertTrue(User::factory()->create()->entitlementSet()->isEmpty());
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function statuses(): array
    {
        return [
            'paid and running' => [['stripe_status' => 'active'], true],
            'on a trial' => [['stripe_status' => 'trialing', 'trial_ends_at' => '+7 days'], true],
            'cancelled, still inside the period paid for' => [['stripe_status' => 'active', 'ends_at' => '+10 days'], true],
            'cancelled and over' => [['stripe_status' => 'canceled', 'ends_at' => '-1 day'], false],
            'past due' => [['stripe_status' => 'past_due'], false],
            'not yet paid for' => [['stripe_status' => 'incomplete'], false],
            'never paid for' => [['stripe_status' => 'incomplete_expired'], false],
            'unpaid' => [['stripe_status' => 'unpaid'], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    #[Test]
    #[DataProvider('statuses')]
    public function a_subscription_gives_what_its_plan_gives_only_while_cashier_counts_it_as_live(array $state, bool $gives): void
    {
        $user = User::factory()->create();

        foreach (['trial_ends_at', 'ends_at'] as $date) {
            if (isset($state[$date])) {
                $state[$date] = now()->modify($state[$date]);
            }
        }

        $this->subscribe($user, $this->plan(['reports' => null]), $state);

        $this->assertSame($gives, $user->canUse('reports'));
    }

    #[Test]
    public function a_subscription_to_a_price_no_plan_carries_gives_nothing(): void
    {
        $user = User::factory()->create();

        $this->plan(['reports' => null]);
        Subscription::create(['user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_orphan', 'stripe_status' => 'active', 'stripe_price' => 'price_nobody_sells', 'quantity' => 1]);
        Subscription::create(['user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_many_prices', 'stripe_status' => 'active', 'stripe_price' => null, 'quantity' => 1]);

        $this->assertTrue($user->entitlementSet()->isEmpty());
    }

    #[Test]
    public function a_plan_switched_off_on_the_pricing_page_still_serves_the_people_on_it(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, $this->plan(['reports' => null], ['is_active' => false]));

        $this->assertTrue($user->canUse('reports'));
    }

    // --- Changes reach the account at once ---------------------------------------------------------------------------------------

    #[Test]
    public function swapping_to_another_plan_changes_what_the_user_can_do_at_once(): void
    {
        $user = User::factory()->create();
        $starter = $this->plan(['projects' => 3]);
        $pro = $this->plan(['projects' => 50, 'reports' => null]);
        $subscription = $this->subscribe($user, $starter);

        $this->assertSame(3, $user->limitOf('projects'));
        $this->assertFalse($user->canUse('reports'));

        $subscription->forceFill(['stripe_price' => $pro->stripe_price_id])->save();

        $this->assertSame(50, $user->limitOf('projects'));
        $this->assertTrue($user->canUse('reports'));

        // And back again: nothing was kept from the plan before.
        $subscription->forceFill(['stripe_price' => $starter->stripe_price_id])->save();

        $this->assertSame(3, $user->limitOf('projects'));
        $this->assertFalse($user->canUse('reports'));
    }

    #[Test]
    public function what_a_user_holds_follows_the_subscription_ending_and_starting_again(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $this->plan(['reports' => null]));

        $this->assertTrue($user->canUse('reports'));

        $subscription->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()->subMinute()])->save();
        $this->assertFalse($user->canUse('reports'));

        $subscription->forceFill(['stripe_status' => 'active', 'ends_at' => null])->save();
        $this->assertTrue($user->canUse('reports'));
    }

    #[Test]
    public function changing_what_a_plan_gives_reaches_the_people_already_on_it(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(['projects' => 3]);
        $this->subscribe($user, $plan);

        $this->assertSame(3, $user->limitOf('projects'));

        SubscriptionPlanEntitlement::query()->where('subscription_plan_id', $plan->id)->update(['value' => 8]);
        $this->assertSame(8, $user->limitOf('projects'));

        $this->gives($plan, ['reports' => null]);
        $this->assertTrue($user->canUse('reports'));

        $plan->entitlementLinks()->delete();
        $this->assertTrue($user->entitlementSet()->isEmpty());
    }

    // --- Together with purchases -------------------------------------------------------------------------------------------------

    #[Test]
    public function a_plan_and_a_purchase_add_up(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, $this->plan(['projects' => 10, 'reports' => null]));

        $projects = Entitlement::query()->where('key', 'projects')->firstOrFail();
        UserEntitlement::factory()->create([
            'entitlement_id' => $projects->id,
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->id,
            'value' => 5,
        ]);

        $set = $user->entitlementSet();

        $this->assertSame(15, $set->limit('projects'), 'a limit from the plan and one from a purchase are held together');
        $this->assertSame(['reports'], $set->features());
    }

    #[Test]
    public function two_live_subscriptions_each_give_what_their_plan_gives(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(['projects' => 4]);
        $this->subscribe($user, $plan);
        $this->subscribe($user, $plan);

        $this->assertSame(8, $user->limitOf('projects'));
    }

    #[Test]
    public function what_a_user_holds_is_described_with_what_their_plan_gives_added_to_what_they_bought(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, $this->plan(['projects' => 10, 'reports' => null]));

        $projects = Entitlement::query()->where('key', 'projects')->firstOrFail();
        UserEntitlement::factory()->create(['entitlement_id' => $projects->id, 'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'value' => 5]);

        $held = collect(app(Entitlements::class)->held($user))->keyBy('key');

        $this->assertSame(['projects', 'reports'], $held->keys()->sort()->values()->all());
        $this->assertSame(15, $held['projects']['value']);
        $this->assertSame('limit', $held['projects']['type']);
        $this->assertNull($held['reports']['value']);
    }

    #[Test]
    public function something_that_is_not_a_user_holds_nothing_from_a_plan(): void
    {
        $this->plan(['reports' => null]);
        $product = Product::factory()->create();

        DB::enableQueryLog();
        $isEmpty = app(Entitlements::class)->for($product)->isEmpty();
        $asked = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'subscriptions'));
        DB::disableQueryLog();

        $this->assertTrue($isEmpty);
        // Not merely an empty answer: SQLite reads a column that is not there as a string and finds nothing, MySQL fails.
        $this->assertCount(0, $asked, 'only an account is asked about subscriptions');
    }

    // --- Where it is asked -------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_page_and_the_route_middleware_follow_the_plan(): void
    {
        Route::middleware(['web', 'entitled:reports'])->get('/__plan-reports', fn () => 'let in');

        $user = User::factory()->create();
        $plan = $this->plan(['reports' => null, 'projects' => 10]);

        $this->actingAs($user)->get('/__plan-reports')->assertForbidden();

        $subscription = $this->subscribe($user, $plan);

        $this->actingAs($user)->get('/__plan-reports')->assertOk()->assertSee('let in');
        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.entitlements.features', ['reports'])
            ->where('auth.entitlements.limits.projects', 10));

        $subscription->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()->subMinute()])->save();

        $this->actingAs($user)->get('/__plan-reports')->assertForbidden();
    }

    // --- The tables -----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_plan_that_goes_takes_what_it_gave_with_it_and_an_entitlement_that_goes_leaves_the_plan(): void
    {
        $plan = $this->plan(['reports' => null, 'projects' => 3]);
        $entitlement = Entitlement::query()->where('key', 'reports')->firstOrFail();

        $entitlement->delete();
        $this->assertSame(1, $plan->entitlementLinks()->count());

        $plan->delete();
        $this->assertSame(0, SubscriptionPlanEntitlement::query()->count());
    }
}
