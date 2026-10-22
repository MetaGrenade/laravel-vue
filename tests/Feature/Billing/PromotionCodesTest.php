<?php

namespace Tests\Feature\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Billing\PromotionCodeRejected;
use App\Support\Billing\PromotionCodes;
use App\Support\Billing\PromotionOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\CurlClient;
use Tests\Support\RoutedStripeHttpClient;
use Tests\Support\ScriptedStripeHttpClient;
use Tests\TestCase;

/**
 * A promotion code is looked up at Stripe before anybody pays: whether it can be used on the plan, what it takes off, and any
 * condition Stripe will hold it to. Stripe is the real SDK here, answering with what it would send.
 */
class PromotionCodesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake']);

        $this->user = User::factory()->create(['stripe_id' => 'cus_me']);
        $this->plan = SubscriptionPlan::factory()->create(['name' => 'Pro', 'stripe_price_id' => 'price_pro', 'price' => 2900, 'currency' => 'USD']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function promotion(array $over = []): array
    {
        return [
            'id' => 'promo_1',
            'object' => 'promotion_code',
            'active' => true,
            'code' => 'SPRING20',
            'customer' => null,
            'customer_account' => null,
            'expires_at' => null,
            'max_redemptions' => null,
            'promotion' => ['type' => 'coupon', 'coupon' => 'cpn_20'],
            'restrictions' => ['first_time_transaction' => false, 'minimum_amount' => null, 'minimum_amount_currency' => null],
            'times_redeemed' => 0,
            ...$over,
        ];
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function coupon(array $over = []): array
    {
        return [
            'id' => 'cpn_20',
            'object' => 'coupon',
            'percent_off' => 20.0,
            'amount_off' => null,
            'currency' => null,
            'duration' => 'repeating',
            'duration_in_months' => 3,
            'valid' => true,
            ...$over,
        ];
    }

    /**
     * Stripe with the given promotion codes under the name asked for, and the coupon behind them.
     *
     * @param  list<array<string, mixed>>|null  $promotions
     * @param  array<string, mixed>|null  $coupon
     * @param  array<string, mixed>  $extra  more routes
     */
    private function stripe(?array $promotions = null, ?array $coupon = null, array $extra = []): RoutedStripeHttpClient
    {
        $client = new RoutedStripeHttpClient([
            'GET /v1/promotion_codes' => ScriptedStripeHttpClient::page($promotions ?? [$this->promotion()], false, '/v1/promotion_codes'),
            'GET /v1/coupons/cpn_20' => $coupon ?? $this->coupon(),
            'GET /v1/prices/price_pro' => ['id' => 'price_pro', 'object' => 'price', 'product' => 'prod_pro'],
            ...$extra,
        ]);

        ApiRequestor::setHttpClient($client);

        return $client;
    }

    private function find(string $code = 'SPRING20'): PromotionOffer
    {
        return app(PromotionCodes::class)->find($this->user, $this->plan, $code);
    }

    /**
     * What a customer is told about a code that cannot be used.
     */
    private function rejection(string $code = 'SPRING20'): string
    {
        try {
            $this->find($code);
        } catch (PromotionCodeRejected $exception) {
            return $exception->errors()['promotion_code'][0];
        }

        $this->fail('The code was accepted.');
    }

    // --- What a good code says ------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_code_stripe_has_says_what_it_takes_off(): void
    {
        $client = $this->stripe();

        $offer = $this->find('  spring20 ');

        $this->assertSame('promo_1', $offer->id);
        $this->assertSame('SPRING20', $offer->code);
        $this->assertSame('20% off for 3 months', $offer->summary);
        $this->assertSame([], $offer->notes);
        $this->assertSame(['code' => 'SPRING20', 'summary' => '20% off for 3 months', 'notes' => []], $offer->toArray());

        // Stripe is asked for that code, as typed but trimmed (it ignores case), and only for one that can still be used.
        $list = $client->callsTo('GET /v1/promotion_codes')[0]['params'];
        $this->assertSame('spring20', $list['code']);
        $this->assertSame('true', $list['active'], 'sent as Stripe wants a boolean');
        $this->assertSame(['expand' => ['applies_to']], $client->callsTo('GET /v1/coupons/cpn_20')[0]['params'], 'the products a coupon is limited to are only sent when asked for');
    }

    #[Test]
    public function how_long_it_lasts_is_said_in_words(): void
    {
        $this->stripe(coupon: $this->coupon(['duration' => 'once', 'duration_in_months' => null]));
        $this->assertSame('20% off your first payment', $this->find()->summary);

        $this->stripe(coupon: $this->coupon(['duration' => 'forever', 'duration_in_months' => null]));
        $this->assertSame('20% off every payment', $this->find()->summary);

        $this->stripe(coupon: $this->coupon(['duration_in_months' => 1]));
        $this->assertSame('20% off for 1 month', $this->find()->summary);
    }

    #[Test]
    public function a_percentage_is_shown_without_needless_decimals(): void
    {
        $this->stripe(coupon: $this->coupon(['percent_off' => 12.5]));
        $this->assertSame('12.5% off for 3 months', $this->find()->summary);

        $this->stripe(coupon: $this->coupon(['percent_off' => 100.0]));
        $this->assertSame('100% off for 3 months', $this->find()->summary);
    }

    #[Test]
    public function an_amount_off_is_shown_in_the_plans_currency(): void
    {
        $this->stripe(coupon: $this->coupon(['percent_off' => null, 'amount_off' => 500, 'currency' => 'usd', 'duration' => 'once', 'duration_in_months' => null]));

        $this->assertSame('5.00 USD off your first payment', $this->find()->summary);
    }

    #[Test]
    public function an_amount_off_set_for_the_plans_currency_is_the_one_shown(): void
    {
        $this->plan->forceFill(['currency' => 'EUR'])->save();
        $this->stripe(coupon: $this->coupon([
            'percent_off' => null, 'amount_off' => 500, 'currency' => 'usd', 'duration' => 'once', 'duration_in_months' => null,
            'currency_options' => ['eur' => ['amount_off' => 450]],
        ]));

        $this->assertSame('4.50 EUR off your first payment', $this->find()->summary);
    }

    // --- A code that will not do ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_code_stripe_does_not_have_is_refused(): void
    {
        $this->stripe(promotions: []);

        $this->assertSame("That code isn't valid, or it has expired.", $this->rejection('NOSUCHCODE'));
    }

    #[Test]
    public function something_that_cannot_be_a_code_is_refused_without_asking_stripe(): void
    {
        $client = $this->stripe();

        foreach (['', '   ', 'two words', 'sp!ring', str_repeat('A', 65), "SPRING20\n; drop"] as $junk) {
            $this->assertSame("That code isn't valid, or it has expired.", $this->rejection($junk), "'{$junk}'");
        }

        $this->assertSame([], $client->requests);
    }

    #[Test]
    public function a_code_made_for_another_customer_is_not_this_customers(): void
    {
        $this->stripe(promotions: [$this->promotion(['customer' => 'cus_someone_else'])]);

        $this->assertSame("That code isn't valid, or it has expired.", $this->rejection());
    }

    #[Test]
    public function a_code_made_for_an_account_is_not_offered_to_a_customer(): void
    {
        $this->stripe(promotions: [$this->promotion(['customer_account' => 'acct_1'])]);

        $this->assertSame("That code isn't valid, or it has expired.", $this->rejection());
    }

    #[Test]
    public function a_code_made_for_this_customer_is_theirs_and_is_preferred_to_the_one_for_everybody(): void
    {
        $this->stripe(promotions: [
            $this->promotion(['id' => 'promo_everybody']),
            $this->promotion(['id' => 'promo_someone_else', 'customer' => 'cus_someone_else']),
            $this->promotion(['id' => 'promo_mine', 'customer' => 'cus_me']),
        ]);

        $this->assertSame('promo_mine', $this->find()->id);
    }

    #[Test]
    public function the_code_for_everybody_is_found_among_ones_made_for_others(): void
    {
        $this->stripe(promotions: [
            $this->promotion(['id' => 'promo_someone_else', 'customer' => 'cus_someone_else']),
            $this->promotion(['id' => 'promo_everybody']),
        ]);

        $this->assertSame('promo_everybody', $this->find()->id);
    }

    #[Test]
    public function a_customer_stripe_does_not_know_yet_cannot_use_a_code_made_for_a_customer(): void
    {
        $this->user->forceFill(['stripe_id' => null])->save();
        $this->stripe(promotions: [$this->promotion(['customer' => 'cus_someone_else'])]);

        $this->assertSame("That code isn't valid, or it has expired.", $this->rejection());
    }

    #[Test]
    public function an_amount_off_that_lasts_forever_cannot_be_used_on_a_subscription(): void
    {
        $this->stripe(coupon: $this->coupon(['percent_off' => null, 'amount_off' => 500, 'currency' => 'usd', 'duration' => 'forever', 'duration_in_months' => null]));

        $this->assertSame("That code can't be used on a subscription.", $this->rejection());
    }

    #[Test]
    public function an_amount_off_in_another_currency_is_refused(): void
    {
        $this->plan->forceFill(['currency' => 'EUR'])->save();
        $this->stripe(coupon: $this->coupon(['percent_off' => null, 'amount_off' => 500, 'currency' => 'usd', 'duration' => 'once', 'duration_in_months' => null]));

        $this->assertSame("That code can't be used with this plan's currency.", $this->rejection());
    }

    #[Test]
    public function a_percentage_works_in_any_currency(): void
    {
        $this->plan->forceFill(['currency' => 'JPY'])->save();
        $this->stripe();

        $this->assertSame('20% off for 3 months', $this->find()->summary);
    }

    #[Test]
    public function a_code_limited_to_other_products_takes_nothing_off_this_plan(): void
    {
        $client = $this->stripe(coupon: $this->coupon(['applies_to' => ['products' => ['prod_other']]]));

        $this->assertSame("That code doesn't apply to the Pro plan.", $this->rejection());
        $this->assertCount(1, $client->callsTo('GET /v1/prices/price_pro'));
    }

    #[Test]
    public function a_code_limited_to_this_plans_product_is_accepted(): void
    {
        $this->stripe(coupon: $this->coupon(['applies_to' => ['products' => ['prod_other', 'prod_pro']]]));

        $this->assertSame('20% off for 3 months', $this->find()->summary);
    }

    #[Test]
    public function the_plans_product_is_only_looked_up_when_the_code_is_limited_to_products(): void
    {
        $client = $this->stripe();

        $this->find();

        $this->assertSame([], $client->callsTo('GET /v1/prices/price_pro'));
    }

    #[Test]
    public function a_product_stripe_sends_in_full_is_recognised(): void
    {
        $this->stripe(
            coupon: $this->coupon(['applies_to' => ['products' => ['prod_pro']]]),
            extra: ['GET /v1/prices/price_pro' => ['id' => 'price_pro', 'object' => 'price', 'product' => ['id' => 'prod_pro', 'object' => 'product']]],
        );

        $this->assertSame('20% off for 3 months', $this->find()->summary);
    }

    // --- What Stripe holds it to ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_conditions_stripe_will_check_are_shown_as_notes(): void
    {
        $this->stripe(promotions: [$this->promotion(['restrictions' => [
            'first_time_transaction' => true,
            'minimum_amount' => 5000,
            'minimum_amount_currency' => 'usd',
        ]])]);

        $this->assertSame(['Only for first-time customers.', 'Needs a first payment of at least 50.00 USD.'], $this->find()->notes);
    }

    #[Test]
    public function a_minimum_set_for_the_plans_currency_is_the_one_shown(): void
    {
        $this->plan->forceFill(['currency' => 'EUR'])->save();
        $this->stripe(promotions: [$this->promotion(['restrictions' => [
            'first_time_transaction' => false,
            'minimum_amount' => 5000,
            'minimum_amount_currency' => 'usd',
            'currency_options' => ['eur' => ['minimum_amount' => 4500]],
        ]])]);

        $this->assertSame(['Needs a first payment of at least 45.00 EUR.'], $this->find()->notes);
    }

    #[Test]
    public function a_minimum_that_cannot_be_put_in_the_plans_currency_is_still_mentioned(): void
    {
        $this->plan->forceFill(['currency' => 'EUR'])->save();
        $this->stripe(promotions: [$this->promotion(['restrictions' => [
            'first_time_transaction' => false,
            'minimum_amount' => 5000,
            'minimum_amount_currency' => 'usd',
        ]])]);

        $this->assertSame(['Has a minimum payment.'], $this->find()->notes);
    }

    // --- When Stripe cannot be asked ------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_code_is_neither_accepted_nor_refused_when_stripe_cannot_be_reached(): void
    {
        $this->stripe(extra: ['GET /v1/promotion_codes' => fn () => throw new ApiConnectionException('Could not connect')]);

        $this->assertStringContainsString("couldn't check that code just now", $this->rejection());
    }

    #[Test]
    public function a_stripe_error_in_the_middle_of_the_check_is_the_same(): void
    {
        $this->stripe(extra: ['GET /v1/coupons/cpn_20' => ScriptedStripeHttpClient::error(500, 'Something went wrong', 'api_error')]);

        $this->assertStringContainsString("couldn't check that code just now", $this->rejection());
    }

    #[Test]
    public function a_promotion_code_with_no_coupon_behind_it_is_refused(): void
    {
        $this->stripe(promotions: [$this->promotion(['promotion' => ['type' => 'coupon', 'coupon' => null]])]);

        $this->assertSame("That code isn't valid, or it has expired.", $this->rejection());
    }
}
