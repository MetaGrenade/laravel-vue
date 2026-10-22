<?php

namespace Tests\Feature\Admin;

use App\Models\BillingWebhookCall;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may do what on the billing pages. Looking needs `billing.acp.view`; each way of changing something needs its own
 * permission (`create`, `edit`, `delete`), so a person who is let in to read invoices and webhooks cannot also make, change or
 * remove a plan or run a delivery again.
 */
class BillingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const CHANGES = ['billing.acp.create', 'billing.acp.edit', 'billing.acp.delete'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Every billing route: the permission it needs, how it is called, which route, and what it is called on.
     *
     * @return array<string, array{string, string, string, string|null}>
     */
    public static function actions(): array
    {
        return [
            'list the plans' => ['billing.acp.view', 'get', 'acp.billing.plans.index', null],
            'list the invoices' => ['billing.acp.view', 'get', 'acp.billing.invoices.index', null],
            'list the webhook deliveries' => ['billing.acp.view', 'get', 'acp.billing.webhooks.index', null],
            'open a webhook delivery' => ['billing.acp.view', 'get', 'acp.billing.webhooks.show', 'call'],
            'open the form for a new plan' => ['billing.acp.create', 'get', 'acp.billing.plans.create', null],
            'make a plan' => ['billing.acp.create', 'post', 'acp.billing.plans.store', null],
            'open the form for a plan' => ['billing.acp.edit', 'get', 'acp.billing.plans.edit', 'plan'],
            'change a plan' => ['billing.acp.edit', 'put', 'acp.billing.plans.update', 'plan'],
            'choose what a plan gives' => ['billing.acp.edit', 'put', 'acp.billing.plans.entitlements.update', 'plan'],
            'run a webhook delivery again' => ['billing.acp.edit', 'post', 'acp.billing.webhooks.replay', 'call'],
            'delete a plan' => ['billing.acp.delete', 'delete', 'acp.billing.plans.destroy', 'plan'],
        ];
    }

    /**
     * The actions that change something, which need more than view.
     *
     * @return array<string, array{string, string, string, string|null}>
     */
    public static function changes(): array
    {
        return array_filter(self::actions(), fn (array $action) => $action[0] !== 'billing.acp.view');
    }

    private function staff(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function attempt(User $user, string $method, string $route, ?string $on)
    {
        $parameter = match ($on) {
            'plan' => SubscriptionPlan::factory()->create(),
            'call' => BillingWebhookCall::factory()->create(),
            default => null,
        };

        // Nothing is sent that would be valid: what is asked is whether the request gets as far as the action, not what it does.
        return $this->actingAs($user)->{$method}($parameter === null ? route($route) : route($route, $parameter));
    }

    // --- Looking ---------------------------------------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('actions')]
    public function a_user_who_can_only_view_may_only_look(string $needs, string $method, string $route, ?string $on): void
    {
        $response = $this->attempt($this->staff('billing.acp.view'), $method, $route, $on);

        if ($needs === 'billing.acp.view') {
            $this->assertNotSame(403, $response->status(), 'looking is what view is for');
        } else {
            $response->assertForbidden();
        }
    }

    #[Test]
    #[DataProvider('actions')]
    public function a_user_with_no_billing_permission_may_do_nothing(string $needs, string $method, string $route, ?string $on): void
    {
        $this->attempt($this->staff(), $method, $route, $on)->assertForbidden();
    }

    // --- Changing ----------------------------------------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('actions')]
    public function the_permission_for_an_action_lets_a_viewer_do_it(string $needs, string $method, string $route, ?string $on): void
    {
        $response = $this->attempt($this->staff('billing.acp.view', $needs), $method, $route, $on);

        $this->assertNotSame(403, $response->status());
    }

    #[Test]
    #[DataProvider('changes')]
    public function the_permission_for_another_action_does_not(string $needs, string $method, string $route, ?string $on): void
    {
        foreach (self::CHANGES as $other) {
            if ($other === $needs) {
                continue;
            }

            $this->attempt($this->staff('billing.acp.view', $other), $method, $route, $on)->assertForbidden();
        }
    }

    #[Test]
    #[DataProvider('changes')]
    public function a_permission_to_change_something_is_not_enough_without_view(string $needs, string $method, string $route, ?string $on): void
    {
        // The billing pages are reached with view; the permission for an action is asked for on top of it.
        $this->attempt($this->staff($needs), $method, $route, $on)->assertForbidden();
    }

    #[Test]
    public function the_admin_role_may_do_everything(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        foreach (self::actions() as [, $method, $route, $on]) {
            $this->assertNotSame(403, $this->attempt($admin, $method, $route, $on)->status(), $route);
        }
    }

    #[Test]
    public function the_editor_and_moderator_roles_hold_no_billing_permission(): void
    {
        foreach (['editor', 'moderator'] as $role) {
            $user = User::factory()->create()->assignRole($role);

            $this->attempt($user, 'get', 'acp.billing.plans.index', null)->assertForbidden();
            $this->attempt($user, 'post', 'acp.billing.plans.store', null)->assertForbidden();
        }
    }

    // --- What the screens offer -------------------------------------------------------------------------------------------------

    #[Test]
    public function the_plans_page_offers_only_what_the_user_may_do(): void
    {
        SubscriptionPlan::factory()->create();

        $offers = fn (User $user) => $this->actingAs($user)->get(route('acp.billing.plans.index'))->assertInertia(fn (Assert $page) => $page->component('acp/BillingPlans'));

        $offers($this->staff('billing.acp.view'))->assertInertia(fn (Assert $page) => $page
            ->where('can.create', false)->where('can.edit', false)->where('can.delete', false));

        $offers($this->staff('billing.acp.view', 'billing.acp.create'))->assertInertia(fn (Assert $page) => $page
            ->where('can.create', true)->where('can.edit', false)->where('can.delete', false));

        $offers($this->staff('billing.acp.view', 'billing.acp.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('can.create', false)->where('can.edit', true)->where('can.delete', false));

        $offers($this->staff('billing.acp.view', 'billing.acp.delete'))->assertInertia(fn (Assert $page) => $page
            ->where('can.create', false)->where('can.edit', false)->where('can.delete', true));
    }

    #[Test]
    public function a_webhook_delivery_offers_to_run_it_again_only_to_who_may(): void
    {
        $call = BillingWebhookCall::factory()->create();

        $this->actingAs($this->staff('billing.acp.view'))->get(route('acp.billing.webhooks.show', $call))
            ->assertInertia(fn (Assert $page) => $page->where('can.replay', false));

        $this->actingAs($this->staff('billing.acp.view', 'billing.acp.edit'))->get(route('acp.billing.webhooks.show', $call))
            ->assertInertia(fn (Assert $page) => $page->where('can.replay', true));
    }

    #[Test]
    public function refused_requests_change_nothing(): void
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Pro']);
        $viewer = $this->staff('billing.acp.view');

        $this->actingAs($viewer)->put(route('acp.billing.plans.update', $plan), ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.billing.plans.destroy', $plan))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.billing.plans.store'), ['name' => 'New'])->assertForbidden();

        $this->assertSame('Pro', $plan->fresh()->name);
        $this->assertSame(1, SubscriptionPlan::query()->count());
    }
}
