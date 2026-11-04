<?php

namespace Tests\Feature\Webhooks;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Notifications\ConfirmPayment;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Support\ScriptedStripeHttpClient;
use Tests\TestCase;

/**
 * The events that Cashier's own webhook controller handles (and the ones nobody handles) go through the site's
 * controller too. They answered 200 in Cashier, but the site's controller promised an `Illuminate\Http\Response`
 * and Cashier hands back a Symfony one, so Stripe was told 500 and sent them again for three days.
 */
class StripeCashierEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashier.secret' => 'sk_test_fake',
            'cashier.webhook.secret' => 'whsec_test',
            'cashier.webhook.cli_secret' => null,
        ]);

        $this->user = User::factory()->create(['stripe_id' => 'cus_events']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function event(string $type, array $object, string $id = 'evt_cashier_1'): array
    {
        return ['id' => $id, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
    }

    private function deliver(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

        return $this->call('POST', route('stripe.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeSubscription(string $status): array
    {
        return [
            'id' => 'sub_events',
            'object' => 'subscription',
            'customer' => 'cus_events',
            'status' => $status,
            'metadata' => [],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_events',
                'quantity' => 1,
                'price' => ['id' => 'price_events', 'product' => 'prod_events'],
            ]]],
        ];
    }

    #[Test]
    public function a_subscription_made_in_stripe_is_recorded_and_stripe_is_told_so(): void
    {
        $this->deliver($this->event('customer.subscription.created', $this->stripeSubscription('active')))->assertOk();

        $subscription = Subscription::query()->where('stripe_id', 'sub_events')->sole();

        $this->assertSame($this->user->id, $subscription->user_id);
        $this->assertSame('active', $subscription->stripe_status);
        $this->assertSame('price_events', $subscription->stripe_price);
        $this->assertSame(['si_events'], $subscription->items()->pluck('stripe_id')->all());
    }

    #[Test]
    public function a_changed_subscription_is_updated_and_stripe_is_told_so(): void
    {
        $subscription = Subscription::create([
            'user_id' => $this->user->id,
            'type' => 'default',
            'stripe_id' => 'sub_events',
            'stripe_status' => 'past_due',
            'stripe_price' => 'price_old',
        ]);

        $this->deliver($this->event('customer.subscription.updated', $this->stripeSubscription('active')))->assertOk();

        $this->assertSame('active', $subscription->fresh()->stripe_status);
        $this->assertSame('price_events', $subscription->fresh()->stripe_price);
    }

    #[Test]
    public function a_deleted_customer_is_forgotten_and_stripe_is_told_so(): void
    {
        $subscription = Subscription::create([
            'user_id' => $this->user->id,
            'type' => 'default',
            'stripe_id' => 'sub_events',
            'stripe_status' => 'active',
            'stripe_price' => 'price_events',
        ]);

        $this->deliver($this->event('customer.deleted', ['id' => 'cus_events', 'object' => 'customer']))->assertOk();

        $this->assertNull($this->user->fresh()->stripe_id);
        $this->assertSame('canceled', $subscription->fresh()->stripe_status);
    }

    #[Test]
    public function an_event_about_a_customer_the_site_does_not_know_is_acknowledged(): void
    {
        $this->deliver($this->event('customer.updated', ['id' => 'cus_nobody', 'object' => 'customer']))->assertOk();
        $this->deliver($this->event('customer.subscription.created', [...$this->stripeSubscription('active'), 'customer' => 'cus_nobody'], 'evt_cashier_2'))->assertOk();

        $this->assertSame(0, Subscription::query()->count());
    }

    #[Test]
    public function an_event_nobody_handles_is_acknowledged_so_stripe_stops_sending_it(): void
    {
        $this->deliver($this->event('payment_intent.succeeded', ['id' => 'pi_events', 'object' => 'payment_intent']))->assertOk();
        $this->deliver($this->event('customer.created', ['id' => 'cus_new', 'object' => 'customer'], 'evt_cashier_2'))->assertOk();
    }

    // --- invoice.payment_action_required ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function actionRequired(): array
    {
        return $this->event('invoice.payment_action_required', [
            'id' => 'in_events',
            'object' => 'invoice',
            'customer' => 'cus_events',
            'payment_intent' => 'pi_events',
            'metadata' => [],
        ]);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function settingsThatAreNoNotification(): array
    {
        return [
            'not set' => [null],
            'switched off' => [false],
            'empty' => [''],
            'on, which is not a class' => [true],
            'a class that does not exist' => ['App\\Notifications\\NoSuchNotification'],
        ];
    }

    #[Test]
    #[DataProvider('settingsThatAreNoNotification')]
    public function a_payment_that_needs_the_customer_is_acknowledged_when_there_is_no_notification_to_send(mixed $setting): void
    {
        config(['cashier.payment_notification' => $setting]);
        Notification::fake();
        $stripe = new ScriptedStripeHttpClient([]);
        ApiRequestor::setHttpClient($stripe);

        $this->deliver($this->actionRequired())->assertOk();

        Notification::assertNothingSent();
        $this->assertSame([], $stripe->requests, 'with nothing to send there is nothing to ask Stripe either');
    }

    #[Test]
    public function a_setting_that_is_not_a_notification_is_written_to_the_log(): void
    {
        config(['cashier.payment_notification' => true]);
        Log::spy();

        $this->deliver($this->actionRequired())->assertOk();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'CASHIER_PAYMENT_NOTIFICATION'));
    }

    #[Test]
    public function no_warning_is_written_when_the_setting_is_simply_off(): void
    {
        config(['cashier.payment_notification' => null]);
        Log::spy();

        $this->deliver($this->actionRequired())->assertOk();

        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_notification_class_in_the_setting_still_reaches_the_customer(): void
    {
        config(['cashier.payment_notification' => ConfirmPayment::class]);
        Notification::fake();
        ApiRequestor::setHttpClient(new ScriptedStripeHttpClient([
            [200, ['id' => 'pi_events', 'object' => 'payment_intent', 'status' => 'requires_action', 'amount' => 1000, 'currency' => 'usd']],
        ]));

        $this->deliver($this->actionRequired())->assertOk();

        Notification::assertSentTo($this->user, ConfirmPayment::class);
    }

    #[Test]
    public function the_shipped_setting_is_none(): void
    {
        $this->assertNull(config('cashier.payment_notification'), 'config/cashier.php must not default to something that is not a notification class');
    }
}
