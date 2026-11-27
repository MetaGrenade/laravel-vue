<?php

namespace Tests\Feature\Webhooks;

use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cashier registers two routes by itself: a page where a customer confirms a payment, and a webhook of its own. The site keeps
 * the page and not the webhook. Stripe's events go to `stripe/webhook`, which always checks the signature. Cashier's own checked
 * it only when STRIPE_WEBHOOK_SECRET was set, so a site without one took what anybody sent it: a customer's Stripe number was
 * enough to give that account a plan, or to cancel its subscriptions.
 */
class CashierRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{?string}>
     */
    public static function signingSecrets(): array
    {
        return [
            'no signing secret' => [null],
            'a signing secret' => ['whsec_test'],
        ];
    }

    #[Test]
    #[DataProvider('signingSecrets')]
    public function an_unsigned_event_sent_to_cashiers_own_webhook_changes_nothing(?string $secret): void
    {
        config(['cashier.webhook.secret' => $secret, 'cashier.webhook.cli_secret' => null]);

        $plan = SubscriptionPlan::factory()->create();
        SubscriptionPlanEntitlement::create([
            'subscription_plan_id' => $plan->id,
            'entitlement_id' => Entitlement::factory()->create(['key' => 'reports', 'label' => 'Reports'])->id,
        ]);

        $user = User::factory()->create(['stripe_id' => 'cus_forged']);

        $created = $this->postJson('/billing/webhook', [
            'id' => 'evt_forged_1',
            'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_forged',
                'customer' => 'cus_forged',
                'status' => 'active',
                'metadata' => [],
                'items' => ['data' => [['id' => 'si_forged', 'quantity' => 1, 'price' => ['id' => $plan->stripe_price_id, 'product' => 'prod_forged']]]],
            ]],
        ]);

        $this->assertFalse($user->fresh()->canUse('reports'));
        $this->assertSame(0, Subscription::query()->count());

        $deleted = $this->postJson('/billing/webhook', [
            'id' => 'evt_forged_2',
            'type' => 'customer.deleted',
            'data' => ['object' => ['id' => 'cus_forged']],
        ]);

        $this->assertSame('cus_forged', $user->fresh()->stripe_id);

        $created->assertNotFound();
        $deleted->assertNotFound();
    }

    #[Test]
    public function the_payment_page_is_the_only_route_cashier_answers(): void
    {
        $cashiers = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RegisteredRoute $route) => str_starts_with($route->getActionName(), 'Laravel\\Cashier\\'))
            ->map(fn (RegisteredRoute $route) => implode(' ', [$route->methods()[0], $route->uri(), $route->getName()]))
            ->values()
            ->all();

        $this->assertSame(['GET billing/payment/{id} cashier.payment'], $cashiers);
        $this->assertFalse(Route::has('cashier.webhook'));
    }

    #[Test]
    public function the_payment_page_still_refuses_to_send_a_customer_to_another_site(): void
    {
        $this->get(route('cashier.payment', ['id' => 'pi_elsewhere', 'redirect' => 'https://elsewhere.example/']))->assertForbidden();
    }

    #[Test]
    public function the_payment_page_is_under_the_path_cashier_is_configured_with(): void
    {
        config(['cashier.path' => 'pay']);

        require base_path('routes/cashier.php');

        $this->assertTrue(
            collect(Route::getRoutes()->getRoutes())->contains(fn (RegisteredRoute $route) => $route->uri() === 'pay/payment/{id}'),
        );
    }
}
