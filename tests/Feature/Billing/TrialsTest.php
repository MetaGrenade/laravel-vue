<?php

namespace Tests\Feature\Billing;

use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use App\Models\User;
use App\Support\Billing\SubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Support\ScriptedStripeHttpClient;
use Tests\TestCase;

/**
 * A plan can give a new customer free days before the first payment. A customer gets one trial, never a second.
 */
class TrialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    private function plan(?int $trialDays, array $state = []): SubscriptionPlan
    {
        return SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_trial_'.uniqid(), 'trial_days' => $trialDays, ...$state]);
    }

    private function user(): User
    {
        return User::factory()->create(['stripe_id' => 'cus_t'.uniqid()]);
    }

    /**
     * Stripe as Cashier talks to it when a subscription is started: it answers the calls by what they are, and keeps them.
     */
    private function stripe(User $user): ScriptedStripeHttpClient
    {
        $client = new class($user->stripe_id) extends ScriptedStripeHttpClient
        {
            public function __construct(private readonly string $customer)
            {
                parent::__construct([]);
            }

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

                $key = strtoupper($method).' '.parse_url($absUrl, PHP_URL_PATH);
                $customer = ['id' => $this->customer, 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]];

                $body = match ($key) {
                    "GET /v1/customers/{$this->customer}", "POST /v1/customers/{$this->customer}" => $customer,
                    'GET /v1/payment_methods/pm_card' => ['id' => 'pm_card', 'object' => 'payment_method', 'type' => 'card', 'customer' => $this->customer, 'card' => ['brand' => 'visa', 'last4' => '4242']],
                    'POST /v1/subscriptions' => $this->startedSubscription($params),
                    default => throw new RuntimeException("Unexpected request to Stripe: {$key}"),
                };

                return [json_encode($body, JSON_THROW_ON_ERROR), 200, []];
            }

            /**
             * @param  array<string, mixed>  $params
             * @return array<string, mixed>
             */
            private function startedSubscription(array $params): array
            {
                $trial = isset($params['trial_end']);

                return [
                    'id' => 'sub_trial_1',
                    'object' => 'subscription',
                    'customer' => $this->customer,
                    'status' => $trial ? 'trialing' : 'active',
                    'trial_end' => $params['trial_end'] ?? null,
                    'latest_invoice' => null,
                    'items' => ['object' => 'list', 'data' => array_map(fn (array $item) => [
                        'id' => 'si_1',
                        'object' => 'subscription_item',
                        'quantity' => 1,
                        'price' => ['id' => $item['price'], 'object' => 'price', 'product' => 'prod_1'],
                    ], array_values((array) ($params['items'] ?? [])))],
                ];
            }
        };

        ApiRequestor::setHttpClient($client);

        return $client;
    }

    /**
     * What was sent to Stripe to start the subscription.
     *
     * @return array<string, mixed>
     */
    private function sentToStart(ScriptedStripeHttpClient $client): array
    {
        $request = collect($client->requests)->first(fn (array $request) => strtoupper($request['method']) === 'POST' && str_ends_with((string) parse_url($request['url'], PHP_URL_PATH), '/v1/subscriptions'));

        $this->assertNotNull($request, 'a subscription was started at Stripe');

        return $request['params'];
    }

    // --- Who gets a trial -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_customer_with_no_subscription_gets_the_trial_of_the_plan_and_a_visitor_too(): void
    {
        $manager = app(SubscriptionManager::class);
        $plan = $this->plan(14);

        $this->assertTrue($manager->eligibleForTrial(null));
        $this->assertTrue($manager->eligibleForTrial($this->user()));
        $this->assertSame(14, $manager->trialDaysFor($this->user(), $plan));
        $this->assertSame(14, $manager->trialDaysFor(null, $plan));
    }

    #[Test]
    public function a_plan_with_no_trial_gives_none(): void
    {
        $manager = app(SubscriptionManager::class);

        $this->assertSame(0, $manager->trialDaysFor($this->user(), $this->plan(null)));
        $this->assertSame(0, $manager->trialDaysFor($this->user(), $this->plan(0)));
    }

    #[Test]
    public function a_customer_who_has_ever_had_a_subscription_does_not_get_another_trial(): void
    {
        $manager = app(SubscriptionManager::class);
        $plan = $this->plan(14);

        foreach (['active', 'trialing', 'canceled', 'incomplete_expired'] as $index => $status) {
            $user = $this->user();
            Subscription::create(['user_id' => $user->id, 'type' => 'default', 'stripe_id' => "sub_old_{$index}", 'stripe_status' => $status, 'stripe_price' => 'price_x', 'quantity' => 1]);

            $this->assertFalse($manager->eligibleForTrial($user), "a {$status} subscription uses up the trial");
            $this->assertSame(0, $manager->trialDaysFor($user, $plan));
        }
    }

    #[Test]
    public function somebody_elses_subscription_does_not_matter(): void
    {
        Subscription::create(['user_id' => $this->user()->id, 'type' => 'default', 'stripe_id' => 'sub_other', 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1]);

        $this->assertTrue(app(SubscriptionManager::class)->eligibleForTrial($this->user()));
    }

    // --- Starting one ---------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function subscribing_to_a_plan_with_a_trial_starts_the_trial_and_charges_nothing_yet(): void
    {
        $user = $this->user();
        $plan = $this->plan(14);
        $reports = Entitlement::factory()->create(['key' => 'reports']);
        SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $reports->id]);
        $client = $this->stripe($user);

        $this->assertFalse($user->canUse('reports'));

        $subscription = app(SubscriptionManager::class)->create($user, $plan, 'pm_card');

        $sent = $this->sentToStart($client);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, (int) $sent['trial_end'], 5, 'Stripe is told when the trial ends');

        $this->assertSame('trialing', $subscription->stripe_status);
        $this->assertTrue($subscription->onTrial());
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $subscription->trial_ends_at->timestamp, 5);
        $this->assertTrue($user->fresh()->canUse('reports'), 'what the plan gives counts from the first day of the trial');
    }

    #[Test]
    public function subscribing_to_a_plan_with_no_trial_starts_no_trial(): void
    {
        $user = $this->user();
        $client = $this->stripe($user);

        $subscription = app(SubscriptionManager::class)->create($user, $this->plan(null), 'pm_card');

        $this->assertArrayNotHasKey('trial_end', $this->sentToStart($client));
        $this->assertFalse($subscription->onTrial());
        $this->assertSame('active', $subscription->stripe_status);
    }

    #[Test]
    public function a_returning_customer_is_charged_from_the_start_whatever_the_plan_offers(): void
    {
        $user = $this->user();
        Subscription::create(['user_id' => $user->id, 'type' => 'old', 'stripe_id' => 'sub_before', 'stripe_status' => 'canceled', 'stripe_price' => 'price_x', 'quantity' => 1, 'ends_at' => now()->subMonth()]);
        $client = $this->stripe($user);

        $subscription = app(SubscriptionManager::class)->create($user, $this->plan(14), 'pm_card');

        $this->assertArrayNotHasKey('trial_end', $this->sentToStart($client));
        $this->assertFalse($subscription->onTrial());
    }

    #[Test]
    public function cancelling_during_the_trial_ends_it_where_it_would_have_ended(): void
    {
        $user = $this->user();
        $this->stripe($user);
        $subscription = app(SubscriptionManager::class)->create($user, $this->plan(14), 'pm_card');

        // Cashier ends a cancelled trial on the day it was to end, so nothing is ever charged.
        $subscription->forceFill(['ends_at' => $subscription->trial_ends_at])->save();

        $this->assertTrue($subscription->fresh()->onTrial());
        $this->assertTrue($subscription->fresh()->canceled());
        $this->assertTrue($subscription->fresh()->valid(), 'it still gives what the plan gives until the day the trial was to end');
    }

    // --- What the pages say ------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_pricing_page_offers_the_trial_to_a_visitor_and_to_a_customer_who_never_subscribed(): void
    {
        $this->plan(14, ['name' => 'Pro']);

        $this->get(route('pricing'))->assertInertia(fn (Assert $page) => $page
            ->where('plans.0.trial_days', 14)
            ->where('trial_eligible', true));

        $this->actingAs($this->user())->get(route('pricing'))->assertInertia(fn (Assert $page) => $page->where('trial_eligible', true));
    }

    #[Test]
    public function the_pricing_page_does_not_offer_the_trial_to_a_customer_who_has_had_one(): void
    {
        $this->plan(14);
        $user = $this->user();
        Subscription::create(['user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_had', 'stripe_status' => 'canceled', 'stripe_price' => 'price_x', 'quantity' => 1, 'ends_at' => now()->subDay()]);

        $this->actingAs($user)->get(route('pricing'))->assertInertia(fn (Assert $page) => $page->where('trial_eligible', false));
    }

    #[Test]
    public function the_billing_settings_say_whether_the_trial_is_theirs_and_when_it_ends(): void
    {
        $this->plan(14);
        $user = $this->user();

        $this->actingAs($user)->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page
            ->where('trial_eligible', true)
            ->where('plans.0.trial_days', 14)
            ->where('subscription', null));

        $plan = SubscriptionPlan::query()->firstOrFail();
        Subscription::create(['user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_on', 'stripe_status' => 'trialing', 'stripe_price' => $plan->stripe_price_id, 'quantity' => 1, 'trial_ends_at' => now()->addDays(9)]);

        $this->actingAs($user->fresh())->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page
            ->where('trial_eligible', false)
            ->where('subscription.on_trial', true)
            ->where('subscription.trial_ends_at', fn ($end) => str_starts_with((string) $end, now()->addDays(9)->format('Y-m-d'))));
    }

    #[Test]
    public function the_reload_after_subscribing_carries_the_new_eligibility(): void
    {
        $plan = $this->plan(14);
        $user = $this->user();

        // What the page asks for once a subscription has started: a partial reload of just these props, which keeps all the others
        // (so a stale "eligible" would keep the trial badges up for someone who is no longer).
        $version = null;
        $this->actingAs($user)->get(route('settings.billing.index'))->assertInertia(function (Assert $page) use (&$version) {
            $version = $page->toArray()['version'];

            $page->where('trial_eligible', true);
        });

        Subscription::create(['user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_started', 'stripe_status' => 'trialing', 'stripe_price' => $plan->stripe_price_id, 'quantity' => 1, 'trial_ends_at' => now()->addDays(14)]);

        $this->actingAs($user->fresh())->get(route('settings.billing.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'settings/Billing',
            'X-Inertia-Partial-Data' => 'subscription,invoices,trial_eligible',
        ])
            ->assertOk()
            ->assertJsonPath('props.trial_eligible', false)
            ->assertJsonPath('props.subscription.on_trial', true)
            ->assertJsonMissingPath('props.plans');
    }

    #[Test]
    public function a_subscription_that_is_not_on_a_trial_says_so(): void
    {
        $user = $this->user();
        $plan = $this->plan(null);
        Subscription::create(['user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_paid', 'stripe_status' => 'active', 'stripe_price' => $plan->stripe_price_id, 'quantity' => 1]);

        $this->actingAs($user)->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page
            ->where('subscription.on_trial', false)
            ->where('subscription.trial_ends_at', null));
    }
}
