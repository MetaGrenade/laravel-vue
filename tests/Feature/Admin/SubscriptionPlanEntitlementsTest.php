<?php

namespace Tests\Feature\Admin;

use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * Choosing what a subscription plan gives, and the guards that stop a plan, a price or an entitlement being changed or
 * removed from under the people who are subscribed to it.
 */
class SubscriptionPlanEntitlementsTest extends TestCase
{
    use InteractsWithTebex;
    use RefreshDatabase;

    private int $subscriptions = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTebex();
        $this->seed(RolePermissionSeeder::class);
    }

    private function staff(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions ?: ['billing.acp.view', 'billing.acp.edit', 'billing.acp.delete']);

        return $user;
    }

    private function subscribe(SubscriptionPlan $plan, array $state = []): Subscription
    {
        return Subscription::create([
            'user_id' => User::factory()->create()->id,
            'type' => 'default',
            'stripe_id' => 'sub_acp_'.(++$this->subscriptions),
            'stripe_status' => 'active',
            'stripe_price' => $plan->stripe_price_id,
            'quantity' => 1,
            ...$state,
        ]);
    }

    private function save(User $user, SubscriptionPlan $plan, array $entitlements)
    {
        return $this->actingAs($user)->put(route('acp.billing.plans.entitlements.update', $plan), ['entitlements' => $entitlements]);
    }

    // --- Choosing what a plan gives -----------------------------------------------------------------------------------------------

    #[Test]
    public function staff_choose_what_a_plan_gives_and_it_is_the_whole_list(): void
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Pro']);
        $reports = Entitlement::factory()->create(['key' => 'reports']);
        $projects = Entitlement::factory()->limit()->create(['key' => 'projects']);
        $seats = Entitlement::factory()->limit()->create(['key' => 'seats']);

        $this->save($this->staff(), $plan, [
            ['id' => $reports->id, 'value' => null],
            ['id' => $projects->id, 'value' => 25],
        ])->assertSessionHas('success', 'What Pro gives was saved.');

        $this->assertEqualsCanonicalizing(
            ['reports' => null, 'projects' => 25],
            $plan->entitlementLinks()->with('entitlement')->get()->mapWithKeys(fn ($link) => [$link->entitlement->key => $link->value])->all(),
        );

        // Saved again: what is left out is taken off, what is kept is updated rather than repeated.
        $this->save($this->staff(), $plan, [
            ['id' => $projects->id, 'value' => 40],
            ['id' => $seats->id, 'value' => 3],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['projects' => 40, 'seats' => 3],
            $plan->entitlementLinks()->with('entitlement')->get()->mapWithKeys(fn ($link) => [$link->entitlement->key => $link->value])->all(),
        );

        $this->save($this->staff(), $plan, [])->assertSessionHas('success', 'Pro no longer gives anything.');
        $this->assertSame(0, $plan->entitlementLinks()->count());
    }

    #[Test]
    public function a_feature_keeps_no_amount_and_a_limit_needs_one(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $feature = Entitlement::factory()->create();
        $limit = Entitlement::factory()->limit()->create(['label' => 'Projects']);

        $this->save($this->staff(), $plan, [['id' => $feature->id, 'value' => 7]])->assertSessionHasNoErrors();
        $this->assertNull($plan->entitlementLinks()->firstOrFail()->value, 'an amount sent with a feature is dropped');

        $this->save($this->staff(), $plan, [['id' => $limit->id, 'value' => null]])
            ->assertSessionHasErrors(['entitlements.0.value' => 'Say how much the plan gives of Projects.']);

        $this->save($this->staff(), $plan, [['id' => $limit->id, 'value' => 0]])->assertSessionHasErrors('entitlements.0.value');
        $this->assertSame(1, $plan->entitlementLinks()->count(), 'a refused list changes nothing');
    }

    #[Test]
    public function an_entitlement_that_does_not_exist_or_is_listed_twice_is_refused(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $feature = Entitlement::factory()->create();

        $this->save($this->staff(), $plan, [['id' => 999999, 'value' => null]])->assertSessionHasErrors('entitlements.0.id');
        $this->save($this->staff(), $plan, [['id' => $feature->id], ['id' => $feature->id]])->assertSessionHasErrors('entitlements.0.id');
        $this->assertSame(0, $plan->entitlementLinks()->count());
    }

    #[Test]
    public function only_staff_who_may_edit_billing_choose_what_a_plan_gives(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $feature = Entitlement::factory()->create();

        $this->save($this->staff('billing.acp.view'), $plan, [['id' => $feature->id]])->assertForbidden();
        $this->save(User::factory()->create(), $plan, [['id' => $feature->id]])->assertForbidden();

        $this->assertSame(0, $plan->entitlementLinks()->count());
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        $this->put(route('acp.billing.plans.entitlements.update', $plan), ['entitlements' => []])->assertRedirect(route('login'));
    }

    #[Test]
    public function the_plans_page_lists_what_each_gives_and_what_could_be_given(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $reports = Entitlement::factory()->create(['key' => 'reports', 'label' => 'Reports']);
        $projects = Entitlement::factory()->limit()->create(['key' => 'projects', 'label' => 'Projects']);
        SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $projects->id, 'value' => 12]);
        SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $reports->id]);

        $this->actingAs($this->staff())->get(route('acp.billing.plans.index'))->assertInertia(fn (Assert $page) => $page
            ->component('acp/BillingPlans')
            ->where('can.edit_entitlements', true)
            ->has('entitlements', 2)
            ->where('plans.0.entitlements', [
                ['id' => $projects->id, 'key' => 'projects', 'label' => 'Projects', 'type' => 'limit', 'value' => 12],
                ['id' => $reports->id, 'key' => 'reports', 'label' => 'Reports', 'type' => 'feature', 'value' => null],
            ]));

        $this->actingAs($this->staff('billing.acp.view'))->get(route('acp.billing.plans.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.edit_entitlements', false));
    }

    // --- Not taking something away from the people on a plan -----------------------------------------------------------------------

    #[Test]
    public function a_plan_people_are_subscribed_to_cannot_be_deleted_but_one_nobody_is_on_can(): void
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Pro']);
        $subscription = $this->subscribe($plan);

        $this->actingAs($this->staff())->delete(route('acp.billing.plans.destroy', $plan))
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'subscribed to Pro now'));
        $this->assertModelExists($plan);

        // Over, so nothing is taken from anyone.
        $subscription->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()])->save();

        $this->actingAs($this->staff())->delete(route('acp.billing.plans.destroy', $plan))->assertSessionHas('success');
        $this->assertModelMissing($plan);
    }

    #[Test]
    public function people_on_another_plan_do_not_stand_in_the_way(): void
    {
        $this->subscribe(SubscriptionPlan::factory()->create(['name' => 'Pro']));
        $empty = SubscriptionPlan::factory()->create(['name' => 'Starter']);

        $this->assertFalse($empty->hasLiveSubscribers());
        $this->actingAs($this->staff())->delete(route('acp.billing.plans.destroy', $empty))->assertSessionHas('success');
        $this->assertModelMissing($empty);
    }

    #[Test]
    public function a_plan_cancelled_but_still_inside_the_period_paid_for_still_counts_as_having_people_on_it(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $this->subscribe($plan, ['ends_at' => now()->addDays(5)]);

        $this->actingAs($this->staff())->delete(route('acp.billing.plans.destroy', $plan))->assertSessionHas('error');
        $this->assertModelExists($plan);
    }

    #[Test]
    public function a_plan_price_cannot_be_pointed_elsewhere_while_people_are_on_it(): void
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Pro', 'stripe_price_id' => 'price_pro']);
        $this->subscribe($plan);

        $update = fn (string $price, string $name) => $this->actingAs($this->staff())->put(route('acp.billing.plans.update', $plan), [
            'name' => $name,
            'slug' => $plan->slug,
            'stripe_price_id' => $price,
            'interval' => 'month',
            'price' => $plan->price,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $update('price_other', 'Pro')->assertSessionHasErrors('stripe_price_id');
        $this->assertSame('price_pro', $plan->fresh()->stripe_price_id);

        // Everything else can still be changed: the price is what ties the subscription to the plan.
        $update('price_pro', 'Pro Plus')->assertSessionHasNoErrors();
        $this->assertSame('Pro Plus', $plan->fresh()->name);
    }

    #[Test]
    public function a_plan_price_can_be_changed_when_nobody_is_on_it(): void
    {
        $plan = SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_pro']);
        $this->subscribe($plan, ['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);

        $this->actingAs($this->staff())->put(route('acp.billing.plans.update', $plan), [
            'name' => $plan->name,
            'slug' => $plan->slug,
            'stripe_price_id' => 'price_new',
            'interval' => 'month',
            'price' => $plan->price,
            'currency' => 'USD',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('price_new', $plan->fresh()->stripe_price_id);
    }

    // --- The list of entitlements ---------------------------------------------------------------------------------------------------

    #[Test]
    public function the_entitlement_list_says_how_many_plans_give_each(): void
    {
        $reports = Entitlement::factory()->create(['key' => 'reports']);
        foreach (SubscriptionPlan::factory()->count(2)->create() as $plan) {
            SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $reports->id]);
        }

        $this->actingAs(User::factory()->create()->assignRole('admin'))->get(route('acp.commerce.entitlements.index'))->assertInertia(fn (Assert $page) => $page
            ->where('entitlements.0.plans_count', 2)
            ->where('entitlements.0.packages_count', 0));
    }

    #[Test]
    public function an_entitlement_a_plan_gives_to_people_now_cannot_be_deleted_until_it_is_taken_off_the_plan(): void
    {
        $user = User::factory()->create()->assignRole('admin');
        $entitlement = Entitlement::factory()->create(['key' => 'reports', 'label' => 'Reports']);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Pro']);
        SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $entitlement->id]);
        $subscription = $this->subscribe($plan);

        $this->actingAs($user)->delete(route('acp.commerce.entitlements.destroy', $entitlement))
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'given by Pro') && str_contains($error, 'Take it off the plan'));
        $this->assertModelExists($entitlement);

        // Nobody is on the plan any more, so nobody loses it: it goes, and the plan stops giving it.
        $subscription->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()])->save();

        $this->actingAs($user)->delete(route('acp.commerce.entitlements.destroy', $entitlement))->assertSessionHas('success');
        $this->assertModelMissing($entitlement);
        $this->assertSame(0, $plan->entitlementLinks()->count());
    }

    #[Test]
    public function the_plans_that_stand_in_the_way_of_deleting_an_entitlement_are_named(): void
    {
        $user = User::factory()->create()->assignRole('admin');
        $entitlement = Entitlement::factory()->create(['label' => 'Reports']);

        foreach (['Pro', 'Team'] as $name) {
            $plan = SubscriptionPlan::factory()->create(['name' => $name]);
            SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $entitlement->id]);
            $this->subscribe($plan);
        }

        // A third plan gives it too, with nobody on it: not mentioned.
        $idle = SubscriptionPlan::factory()->create(['name' => 'Idle']);
        SubscriptionPlanEntitlement::create(['subscription_plan_id' => $idle->id, 'entitlement_id' => $entitlement->id]);

        $this->actingAs($user)->delete(route('acp.commerce.entitlements.destroy', $entitlement))
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'given by Pro and Team,') && ! str_contains($error, 'Idle'));
    }
}
