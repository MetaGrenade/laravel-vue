<?php

namespace Tests\Feature\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Billing\PromotionCodeRejected;
use App\Support\Billing\SubscriptionManager;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\CurlClient;
use Tests\Support\RoutedStripeHttpClient;
use Tests\Support\ScriptedStripeHttpClient;
use Tests\TestCase;

/**
 * A promotion code goes with a new subscription: the page checks it, and starting the subscription looks it up again and hands
 * it to Stripe. A code that will not do stops everything before a card is used or anything is charged.
 */
class PromotionCodeSubscribingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake']);

        $this->user = User::factory()->create(['stripe_id' => 'cus_me']);
        $this->plan = SubscriptionPlan::factory()->create(['name' => 'Pro', 'stripe_price_id' => 'price_pro', 'price' => 2900, 'currency' => 'USD', 'trial_days' => null]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    /**
     * Stripe as it answers when a subscription is started with a code that exists.
     *
     * @param  array<string, mixed>  $routes  Anything to answer differently.
     */
    private function stripe(array $routes = []): RoutedStripeHttpClient
    {
        $client = new RoutedStripeHttpClient([
            'GET /v1/customers/cus_me' => ['id' => 'cus_me', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]],
            'POST /v1/customers/cus_me' => ['id' => 'cus_me', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]],
            'GET /v1/payment_methods/pm_card' => ['id' => 'pm_card', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_me', 'card' => ['brand' => 'visa', 'last4' => '4242']],
            'POST /v1/subscriptions' => fn (array $params) => [
                'id' => 'sub_promo_1',
                'object' => 'subscription',
                'customer' => 'cus_me',
                'status' => isset($params['trial_end']) ? 'trialing' : 'active',
                'trial_end' => $params['trial_end'] ?? null,
                'latest_invoice' => null,
                'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => 'price_pro', 'object' => 'price', 'product' => 'prod_pro']]]],
            ],
            'GET /v1/promotion_codes' => ScriptedStripeHttpClient::page([[
                'id' => 'promo_1', 'object' => 'promotion_code', 'active' => true, 'code' => 'SPRING20', 'customer' => null, 'customer_account' => null,
                'promotion' => ['type' => 'coupon', 'coupon' => 'cpn_20'],
                'restrictions' => ['first_time_transaction' => true, 'minimum_amount' => null, 'minimum_amount_currency' => null],
            ]], false, '/v1/promotion_codes'),
            'GET /v1/coupons/cpn_20' => ['id' => 'cpn_20', 'object' => 'coupon', 'percent_off' => 20.0, 'amount_off' => null, 'currency' => null, 'duration' => 'repeating', 'duration_in_months' => 3, 'valid' => true],
            ...$routes,
        ]);

        ApiRequestor::setHttpClient($client);

        return $client;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function stripeError(string $message, ?string $param = null): array
    {
        return [400, ['error' => array_filter(['message' => $message, 'type' => 'invalid_request_error', 'param' => $param])]];
    }

    private function subscribe(?string $code, ?SubscriptionPlan $plan = null): ?Subscription
    {
        return app(SubscriptionManager::class)->create($this->user, $plan ?? $this->plan, 'pm_card', ['promotion_code' => $code]);
    }

    // --- Starting a subscription with a code ----------------------------------------------------------------------------------------------

    #[Test]
    public function a_code_is_handed_to_stripe_with_the_subscription(): void
    {
        $client = $this->stripe();

        $subscription = $this->subscribe('spring20');

        $this->assertSame('sub_promo_1', $subscription->stripe_id);
        $this->assertSame([['promotion_code' => 'promo_1']], $client->callsTo('POST /v1/subscriptions')[0]['params']['discounts']);
    }

    #[Test]
    public function with_no_code_no_discount_is_asked_for(): void
    {
        $client = $this->stripe();

        foreach ([null, '', '   '] as $nothing) {
            Subscription::query()->delete();
            $this->subscribe($nothing);
        }

        foreach ($client->callsTo('POST /v1/subscriptions') as $call) {
            $this->assertArrayNotHasKey('discounts', $call['params']);
        }

        $this->assertSame([], $client->callsTo('GET /v1/promotion_codes'), 'Stripe is not asked about a code that was not typed');
    }

    #[Test]
    public function a_code_and_a_free_trial_go_together(): void
    {
        $this->plan->forceFill(['trial_days' => 14])->save();
        $client = $this->stripe();

        $this->subscribe('SPRING20');

        $sent = $client->callsTo('POST /v1/subscriptions')[0]['params'];
        $this->assertSame([['promotion_code' => 'promo_1']], $sent['discounts']);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, (int) $sent['trial_end'], 5);
    }

    #[Test]
    public function a_code_that_will_not_do_stops_before_the_card_is_touched_or_anything_is_started(): void
    {
        $client = $this->stripe(['GET /v1/promotion_codes' => ScriptedStripeHttpClient::page([], false, '/v1/promotion_codes')]);

        try {
            $this->subscribe('NOSUCHCODE');
            $this->fail('The subscription was started.');
        } catch (PromotionCodeRejected $exception) {
            $this->assertSame("That code isn't valid, or it has expired.", $exception->errors()['promotion_code'][0]);
        }

        $this->assertSame([], $client->callsTo('POST /v1/subscriptions'));
        $this->assertSame([], $client->callsTo('POST /v1/customers/cus_me'), 'the card is not made the default');
        $this->assertSame(0, Subscription::query()->count());
    }

    #[Test]
    public function the_code_is_looked_up_again_whatever_the_page_found(): void
    {
        // The page may have been open for an hour: the code can have run out since.
        $client = $this->stripe();
        $this->subscribe('SPRING20');
        $this->assertCount(1, $client->callsTo('GET /v1/promotion_codes'));

        Subscription::query()->delete();
        $client = $this->stripe(['GET /v1/promotion_codes' => ScriptedStripeHttpClient::page([], false, '/v1/promotion_codes')]);

        $this->expectException(PromotionCodeRejected::class);
        $this->subscribe('SPRING20');
    }

    // --- When Stripe refuses it at the last step ------------------------------------------------------------------------------------------

    #[Test]
    public function stripe_refusing_a_code_when_the_subscription_starts_is_told_to_the_customer_not_thrown_at_them(): void
    {
        $this->stripe(['POST /v1/subscriptions' => $this->stripeError('This promotion code cannot be redeemed because the customer has prior transactions.', 'discounts[0][promotion_code]')]);

        try {
            $this->subscribe('SPRING20');
            $this->fail('The subscription was started.');
        } catch (PromotionCodeRejected $exception) {
            $this->assertSame("That code can't be used on this subscription. Nothing has been charged.", $exception->errors()['promotion_code'][0]);
        }

        $this->assertSame(0, Subscription::query()->count());
    }

    #[Test]
    public function a_refusal_that_only_names_the_parameter_is_recognised_too(): void
    {
        $this->stripe(['POST /v1/subscriptions' => $this->stripeError('This discount cannot be applied here.', 'discounts[0][promotion_code]')]);

        $this->expectException(PromotionCodeRejected::class);
        $this->subscribe('SPRING20');
    }

    #[Test]
    public function a_refusal_that_only_the_words_say_is_about_the_code_is_recognised_too(): void
    {
        $this->stripe(['POST /v1/subscriptions' => $this->stripeError('This coupon cannot be applied to a subscription with a trial.')]);

        $this->expectException(PromotionCodeRejected::class);
        $this->subscribe('SPRING20');
    }

    #[Test]
    public function a_refusal_about_something_else_is_not_blamed_on_the_code(): void
    {
        $this->stripe(['POST /v1/subscriptions' => $this->stripeError("No such price: 'price_pro'", 'items[0][price]')]);

        $this->expectException(InvalidRequestException::class);
        $this->subscribe('SPRING20');
    }

    #[Test]
    public function a_refusal_about_a_coupon_when_no_code_was_given_is_not_blamed_on_a_code(): void
    {
        $this->stripe(['POST /v1/subscriptions' => $this->stripeError('Something about a coupon on the customer.')]);

        $this->expectException(InvalidRequestException::class);
        $this->subscribe(null);
    }

    // --- The pages' requests --------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_billing_page_checks_a_code_and_shows_what_it_takes_off_without_stripes_id(): void
    {
        $this->stripe();

        $response = $this->actingAs($this->user)->postJson(route('settings.billing.promotion-code'), ['plan_id' => $this->plan->id, 'promotion_code' => 'spring20'])->assertOk();

        $response->assertExactJson([
            'code' => 'SPRING20',
            'summary' => '20% off for 3 months',
            'notes' => ['Only for first-time customers.'],
        ]);
    }

    #[Test]
    public function the_pricing_page_checks_a_code_for_a_visitor_who_has_an_account_by_then(): void
    {
        $this->stripe();

        $this->actingAs($this->user)->postJson(route('pricing.promotion-code'), ['plan_id' => $this->plan->id, 'promotion_code' => 'SPRING20'])
            ->assertOk()
            ->assertJsonPath('summary', '20% off for 3 months');
    }

    #[Test]
    public function a_code_that_will_not_do_is_answered_like_any_bad_input(): void
    {
        $this->stripe(['GET /v1/promotion_codes' => ScriptedStripeHttpClient::page([], false, '/v1/promotion_codes')]);

        foreach (['settings.billing.promotion-code', 'pricing.promotion-code'] as $route) {
            $this->actingAs($this->user)->postJson(route($route), ['plan_id' => $this->plan->id, 'promotion_code' => 'NOSUCHCODE'])
                ->assertStatus(422)
                ->assertJsonPath('errors.promotion_code.0', "That code isn't valid, or it has expired.");
        }
    }

    #[Test]
    public function the_check_needs_a_plan_and_a_code(): void
    {
        $this->actingAs($this->user)->postJson(route('settings.billing.promotion-code'), ['plan_id' => $this->plan->id])->assertJsonValidationErrors('promotion_code');
        $this->actingAs($this->user)->postJson(route('settings.billing.promotion-code'), ['promotion_code' => 'SPRING20'])->assertJsonValidationErrors('plan_id');
        $this->actingAs($this->user)->postJson(route('pricing.promotion-code'), ['plan_id' => 999999, 'promotion_code' => 'SPRING20'])->assertJsonValidationErrors('plan_id');
    }

    #[Test]
    public function a_plan_that_is_not_for_sale_has_no_codes(): void
    {
        $this->stripe();
        $plan = SubscriptionPlan::factory()->create(['is_active' => false, 'stripe_price_id' => 'price_old']);

        foreach (['settings.billing.promotion-code', 'pricing.promotion-code'] as $route) {
            $this->actingAs($this->user)->postJson(route($route), ['plan_id' => $plan->id, 'promotion_code' => 'SPRING20'])->assertNotFound();
        }
    }

    #[Test]
    public function only_somebody_who_has_signed_in_can_check_a_code(): void
    {
        $this->postJson(route('settings.billing.promotion-code'), ['plan_id' => $this->plan->id, 'promotion_code' => 'SPRING20'])->assertUnauthorized();
        $this->postJson(route('pricing.promotion-code'), ['plan_id' => $this->plan->id, 'promotion_code' => 'SPRING20'])->assertUnauthorized();
    }

    #[Test]
    public function guessing_codes_is_slowed_down_whether_they_are_right_or_wrong(): void
    {
        $client = $this->stripe();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($this->user)->postJson(route('settings.billing.promotion-code'), ['plan_id' => $this->plan->id, 'promotion_code' => 'not a code'])->assertStatus(422);
        }

        // The eleventh in the minute is refused, even for a good code, and Stripe is not asked.
        $this->actingAs($this->user)->postJson(route('settings.billing.promotion-code'), ['plan_id' => $this->plan->id, 'promotion_code' => 'SPRING20'])->assertTooManyRequests();
        $this->assertSame([], $client->requests);
    }

    #[Test]
    public function the_allowance_for_trying_codes_is_ten_a_minute_and_sixty_an_hour(): void
    {
        $limiter = RateLimiter::limiter('promotion-codes');

        $limits = $limiter(Request::create('/', 'POST', ['promotion_code' => 'SPRING20']));

        $this->assertSame([[10, 60], [60, 3600]], collect($limits)->map(fn ($limit) => [$limit->maxAttempts, $limit->decaySeconds])->all());

        // A request that carries no code is not a try at one.
        foreach ([[], ['promotion_code' => null], ['promotion_code' => ''], ['promotion_code' => '   ']] as $input) {
            $this->assertInstanceOf(Unlimited::class, $limiter(Request::create('/', 'POST', $input)));
        }
    }

    #[Test]
    public function the_pricing_page_is_slowed_down_too(): void
    {
        $client = $this->stripe();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($this->user)->postJson(route('pricing.promotion-code'), ['plan_id' => $this->plan->id, 'promotion_code' => 'not a code'])->assertStatus(422);
        }

        $this->actingAs($this->user)->postJson(route('pricing.promotion-code'), ['plan_id' => $this->plan->id, 'promotion_code' => 'SPRING20'])->assertTooManyRequests();
        $this->assertSame([], $client->requests);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function subscribeRoutes(): array
    {
        return ['the billing page' => ['settings.billing.subscribe'], 'the pricing page' => ['pricing.subscribe']];
    }

    #[Test]
    #[DataProvider('subscribeRoutes')]
    public function starting_a_subscription_with_a_code_counts_against_the_allowance_for_trying_codes(string $route): void
    {
        $client = $this->stripe();
        $body = ['plan_id' => $this->plan->id, 'payment_method' => 'pm_card'];

        // Ten a minute, a minute apart: never over the per-minute limit, which is what a guesser would stay under, and sixty in the hour.
        for ($minute = 1; $minute <= 6; $minute++) {
            for ($attempt = 1; $attempt <= 10; $attempt++) {
                $this->actingAs($this->user)->postJson(route($route), [...$body, 'promotion_code' => 'not a code'])->assertStatus(422);
            }

            $this->travel(61)->seconds();
        }

        $this->actingAs($this->user)->postJson(route($route), [...$body, 'promotion_code' => 'SPRING20'])->assertTooManyRequests();
        $this->assertSame([], $client->callsTo('GET /v1/promotion_codes'), 'Stripe is not asked');
        $this->assertSame([], $client->callsTo('POST /v1/subscriptions'));

        // Starting a subscription with no code is not a guess at one, so it is not held up by them.
        $this->actingAs($this->user)->postJson(route($route), $body)->assertOk();
    }

    #[Test]
    public function the_billing_pages_subscribe_requests_are_limited_per_minute_whatever_they_carry(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($this->user)->postJson(route('settings.billing.subscribe'), ['plan_id' => $this->plan->id])->assertStatus(422);
        }

        $this->actingAs($this->user)->postJson(route('settings.billing.subscribe'), ['plan_id' => $this->plan->id])->assertTooManyRequests();
    }

    #[Test]
    public function the_billing_page_starts_a_subscription_with_a_code(): void
    {
        $client = $this->stripe();

        $this->actingAs($this->user)->postJson(route('settings.billing.subscribe'), [
            'plan_id' => $this->plan->id,
            'payment_method' => 'pm_card',
            'promotion_code' => 'SPRING20',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame([['promotion_code' => 'promo_1']], $client->callsTo('POST /v1/subscriptions')[0]['params']['discounts']);
    }

    #[Test]
    public function the_pricing_page_starts_a_subscription_with_a_code(): void
    {
        $client = $this->stripe();

        $this->actingAs($this->user)->postJson(route('pricing.subscribe'), [
            'plan_id' => $this->plan->id,
            'payment_method' => 'pm_card',
            'promotion_code' => 'SPRING20',
        ])->assertOk();

        $this->assertSame([['promotion_code' => 'promo_1']], $client->callsTo('POST /v1/subscriptions')[0]['params']['discounts']);
    }

    #[Test]
    public function a_bad_code_on_either_page_is_a_validation_error_and_starts_nothing(): void
    {
        $client = $this->stripe(['GET /v1/promotion_codes' => ScriptedStripeHttpClient::page([], false, '/v1/promotion_codes')]);

        foreach (['settings.billing.subscribe', 'pricing.subscribe'] as $route) {
            $this->actingAs($this->user)->postJson(route($route), ['plan_id' => $this->plan->id, 'payment_method' => 'pm_card', 'promotion_code' => 'NOSUCHCODE'])
                ->assertStatus(422)
                ->assertJsonPath('errors.promotion_code.0', "That code isn't valid, or it has expired.");
        }

        $this->assertSame([], $client->callsTo('POST /v1/subscriptions'));
        $this->assertSame(0, Subscription::query()->count());
    }

    #[Test]
    public function the_old_coupon_field_is_gone(): void
    {
        $client = $this->stripe();

        $this->actingAs($this->user)->postJson(route('settings.billing.subscribe'), [
            'plan_id' => $this->plan->id,
            'payment_method' => 'pm_card',
            'coupon' => 'cpn_20',
        ])->assertOk();

        $this->assertArrayNotHasKey('discounts', $client->callsTo('POST /v1/subscriptions')[0]['params']);
    }

    #[Test]
    public function a_plan_that_is_not_for_sale_cannot_be_subscribed_to_from_the_billing_page(): void
    {
        $client = $this->stripe();
        $plan = SubscriptionPlan::factory()->create(['is_active' => false, 'stripe_price_id' => 'price_old']);

        $this->actingAs($this->user)->postJson(route('settings.billing.subscribe'), ['plan_id' => $plan->id, 'payment_method' => 'pm_card'])->assertNotFound();

        $this->assertSame([], $client->requests);
    }
}
