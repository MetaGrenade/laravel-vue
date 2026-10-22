<?php

namespace Tests\Feature\Billing;

use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use App\Models\User;
use App\Support\Billing\PaymentRetry;
use App\Support\Billing\SubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\CurlClient;
use Tests\Support\ScriptedStripeHttpClient;
use Tests\TestCase;

/**
 * "Pay now": asking Stripe to take what a subscription is behind on, with the card on file, so that a customer who has put
 * their card right does not wait (with their plan paused) for Stripe's next scheduled attempt. The real Stripe SDK, with
 * the network replaced by scripted answers.
 */
class PayNowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake']);

        $this->user = User::factory()->create(['stripe_id' => 'cus_pay1']);

        $plan = SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_pay']);
        $reports = Entitlement::factory()->create(['key' => 'reports']);
        SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $reports->id]);

        $this->subscription = Subscription::create([
            'user_id' => $this->user->id,
            'type' => 'default',
            'stripe_id' => 'sub_pay1',
            'stripe_status' => 'past_due',
            'stripe_price' => 'price_pay',
            'quantity' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        Cashier::$deactivatePastDue = true;

        parent::tearDown();
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function stripeAnswers(array $responses): ScriptedStripeHttpClient
    {
        $client = new ScriptedStripeHttpClient($responses);
        ApiRequestor::setHttpClient($client);

        return $client;
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function stripeSubscription(string $status, mixed $latestInvoice = 'in_pay1'): array
    {
        return [200, ['id' => 'sub_pay1', 'object' => 'subscription', 'status' => $status, 'latest_invoice' => $latestInvoice]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function stripeInvoice(array $extra = []): array
    {
        return [200, ['id' => 'in_pay1', 'object' => 'invoice', 'status' => 'paid', ...$extra]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function cardError(string $code, string $message = 'Your card was declined.'): array
    {
        return [402, ['error' => ['message' => $message, 'type' => 'card_error', 'code' => $code]]];
    }

    private function retry(): PaymentRetry
    {
        return app(SubscriptionManager::class)->retryPayment($this->user->fresh());
    }

    // --- The payment is taken ----------------------------------------------------------------------------------------------------

    #[Test]
    public function the_overdue_invoice_is_paid_and_the_plan_comes_back_at_once(): void
    {
        $client = $this->stripeAnswers([$this->stripeSubscription('past_due'), $this->stripeInvoice(), $this->stripeSubscription('active')]);

        $this->assertFalse($this->user->fresh()->canUse('reports'), 'a payment that is behind takes the plan away');

        $result = $this->retry();

        $this->assertSame(PaymentRetry::PAID, $result->status);
        $this->assertSame('active', $this->subscription->fresh()->stripe_status, 'not left for the webhook to say');
        $this->assertTrue($this->user->fresh()->canUse('reports'));

        $this->assertSame(
            ['GET /v1/subscriptions/sub_pay1', 'POST /v1/invoices/in_pay1/pay', 'GET /v1/subscriptions/sub_pay1'],
            array_map(fn (array $request) => strtoupper($request['method']).' '.parse_url($request['url'], PHP_URL_PATH), $client->requests),
        );
    }

    #[Test]
    public function an_unpaid_subscription_can_be_paid_too(): void
    {
        $this->subscription->forceFill(['stripe_status' => 'unpaid'])->save();
        $this->stripeAnswers([$this->stripeSubscription('unpaid'), $this->stripeInvoice(), $this->stripeSubscription('active')]);

        $this->assertSame(PaymentRetry::PAID, $this->retry()->status);
        $this->assertSame('active', $this->subscription->fresh()->stripe_status);
    }

    // --- It does not go through -------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_declined_card_says_why_and_changes_nothing(): void
    {
        $client = $this->stripeAnswers([$this->stripeSubscription('past_due'), $this->cardError('card_declined', 'Your card has insufficient funds.')]);

        $result = $this->retry();

        $this->assertSame(PaymentRetry::DECLINED, $result->status);
        $this->assertSame('Your card has insufficient funds.', $result->message);
        $this->assertSame('past_due', $this->subscription->fresh()->stripe_status);
        $this->assertCount(2, $client->requests, 'nothing is asked of Stripe after a decline');
    }

    #[Test]
    public function a_card_that_needs_the_customer_is_sent_to_stripes_page_for_the_invoice(): void
    {
        $this->stripeAnswers([
            $this->stripeSubscription('past_due'),
            $this->cardError('authentication_required', 'This payment requires authentication.'),
            $this->stripeInvoice(['status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/test_123']),
        ]);

        $result = $this->retry();

        $this->assertSame(PaymentRetry::ACTION_REQUIRED, $result->status);
        $this->assertSame('https://invoice.stripe.com/i/acct_1/test_123', $result->url);
    }

    #[Test]
    public function a_card_that_needs_the_customer_with_no_page_to_send_them_to_is_a_decline(): void
    {
        $this->stripeAnswers([
            $this->stripeSubscription('past_due'),
            $this->cardError('authentication_required', 'This payment requires authentication.'),
            $this->stripeInvoice(['status' => 'open', 'hosted_invoice_url' => null]),
        ]);

        $result = $this->retry();

        $this->assertSame(PaymentRetry::DECLINED, $result->status);
        $this->assertSame('This payment requires authentication.', $result->message);
    }

    #[Test]
    public function an_invoice_stripe_already_took_is_taken_as_paid(): void
    {
        $this->stripeAnswers([
            $this->stripeSubscription('past_due'),
            [400, ['error' => ['message' => 'Invoice is already paid.', 'type' => 'invalid_request_error']]],
            $this->stripeSubscription('active'),
        ]);

        $this->assertSame(PaymentRetry::PAID, $this->retry()->status);
        $this->assertSame('active', $this->subscription->fresh()->stripe_status);
    }

    #[Test]
    public function an_invoice_that_cannot_be_paid_and_leaves_the_subscription_behind_is_not_called_paid(): void
    {
        $this->stripeAnswers([
            $this->stripeSubscription('past_due'),
            [400, ['error' => ['message' => 'Invoice is not payable.', 'type' => 'invalid_request_error']]],
            $this->stripeSubscription('past_due'),
        ]);

        $this->assertSame(PaymentRetry::NOTHING_DUE, $this->retry()->status);
    }

    // --- Stripe cannot be reached -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function when_stripe_cannot_be_reached_nothing_is_paid_and_the_customer_is_told_to_try_again(): void
    {
        Exceptions::fake();
        $client = $this->stripeAnswers([new ApiConnectionException('Could not connect to Stripe.')]);

        $result = $this->retry();

        $this->assertSame(PaymentRetry::UNAVAILABLE, $result->status);
        $this->assertSame('past_due', $this->subscription->fresh()->stripe_status);
        $this->assertCount(1, $client->requests);

        // The customer is told to try again; the people who run the shop are told why it did not work.
        Exceptions::assertReported(ApiConnectionException::class);
    }

    #[Test]
    public function a_connection_lost_while_paying_is_not_called_paid(): void
    {
        $this->stripeAnswers([$this->stripeSubscription('past_due'), new ApiConnectionException('Connection reset.')]);

        $this->assertSame(PaymentRetry::UNAVAILABLE, $this->retry()->status);
        $this->assertSame('past_due', $this->subscription->fresh()->stripe_status, 'the webhook tells us if it was taken after all');
    }

    #[Test]
    public function a_key_stripe_refuses_is_reported_and_not_shown_to_the_customer(): void
    {
        $this->stripeAnswers([[401, ['error' => ['message' => 'Invalid API Key provided: sk_test_****fake', 'type' => 'invalid_request_error']]]]);

        $result = $this->retry();

        $this->assertSame(PaymentRetry::UNAVAILABLE, $result->status);
        $this->assertNull($result->message, 'what Stripe said about our key is for the logs, not the customer');
    }

    #[Test]
    public function the_button_says_to_try_again_when_stripe_cannot_be_reached(): void
    {
        $this->stripeAnswers([new ApiConnectionException('Could not connect to Stripe.')]);

        $this->actingAs($this->user)->post(route('settings.billing.retry-payment'))
            ->assertRedirect(route('settings.billing.index'))
            ->assertSessionHas('error', "We couldn't reach Stripe just now. Please try again in a minute.");
    }

    // --- Nothing to pay ------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_subscription_that_is_not_behind_is_not_asked_about(): void
    {
        $this->subscription->forceFill(['stripe_status' => 'active'])->save();
        $client = $this->stripeAnswers([]);

        $this->assertSame(PaymentRetry::NOTHING_DUE, $this->retry()->status);
        $this->assertCount(0, $client->requests);
    }

    #[Test]
    public function someone_with_no_subscription_has_nothing_to_pay(): void
    {
        $client = $this->stripeAnswers([]);

        $this->assertSame(PaymentRetry::NOTHING_DUE, app(SubscriptionManager::class)->retryPayment(User::factory()->create())->status);
        $this->assertCount(0, $client->requests);
    }

    #[Test]
    public function a_subscription_with_no_invoice_has_nothing_to_pay(): void
    {
        $client = $this->stripeAnswers([$this->stripeSubscription('past_due', latestInvoice: null)]);

        $this->assertSame(PaymentRetry::NOTHING_DUE, $this->retry()->status);
        $this->assertCount(1, $client->requests, 'no payment was attempted');
    }

    // --- The button -----------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_button_pays_and_says_so(): void
    {
        $this->stripeAnswers([$this->stripeSubscription('past_due'), $this->stripeInvoice(), $this->stripeSubscription('active')]);

        $this->actingAs($this->user)->post(route('settings.billing.retry-payment'))
            ->assertRedirect(route('settings.billing.index'))
            ->assertSessionHas('success', 'Payment received. Your plan is active again.');
    }

    #[Test]
    public function the_button_shows_why_a_card_was_refused(): void
    {
        $this->stripeAnswers([$this->stripeSubscription('past_due'), $this->cardError('card_declined', 'Your card was declined.')]);

        $this->actingAs($this->user)->post(route('settings.billing.retry-payment'))
            ->assertRedirect(route('settings.billing.index'))
            ->assertSessionHas('error', 'Your card was declined.');
    }

    #[Test]
    public function the_button_sends_a_card_that_needs_the_customer_to_stripes_page(): void
    {
        $this->stripeAnswers([
            $this->stripeSubscription('past_due'),
            $this->cardError('authentication_required'),
            $this->stripeInvoice(['hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/test_123']),
        ]);

        $this->actingAs($this->user)->post(route('settings.billing.retry-payment'), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://invoice.stripe.com/i/acct_1/test_123');
    }

    #[Test]
    public function the_button_says_when_there_is_nothing_to_pay(): void
    {
        $this->subscription->forceFill(['stripe_status' => 'active'])->save();
        $this->stripeAnswers([]);

        $this->actingAs($this->user)->post(route('settings.billing.retry-payment'))
            ->assertRedirect(route('settings.billing.index'))
            ->assertSessionHas('info', 'There is nothing to pay right now.');
    }

    #[Test]
    public function only_a_signed_in_customer_can_press_it(): void
    {
        $client = $this->stripeAnswers([]);

        $this->post(route('settings.billing.retry-payment'))->assertRedirect(route('login'));
        $this->assertCount(0, $client->requests);
    }

    // --- What the page says ------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_page_says_when_a_payment_is_behind_and_whether_the_plan_is_paused(): void
    {
        $page = fn () => $this->actingAs($this->user->fresh())->get(route('settings.billing.index'));

        $page()->assertInertia(fn (Assert $page) => $page
            ->where('subscription.needs_payment', true)
            ->where('subscription.access_paused', true));

        Cashier::keepPastDueSubscriptionsActive();
        $page()->assertInertia(fn (Assert $page) => $page
            ->where('subscription.needs_payment', true)
            ->where('subscription.access_paused', false));

        $this->subscription->forceFill(['stripe_status' => 'active'])->save();
        $page()->assertInertia(fn (Assert $page) => $page->where('subscription.needs_payment', false));

        $this->subscription->forceFill(['stripe_status' => 'unpaid'])->save();
        $page()->assertInertia(fn (Assert $page) => $page->where('subscription.needs_payment', true));
    }
}
