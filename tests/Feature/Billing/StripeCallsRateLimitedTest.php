<?php

namespace Tests\Feature\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Support\RoutedStripeHttpClient;
use Tests\TestCase;

/**
 * Every action on the billing settings that puts a question to Stripe has a limit. Making a card ready (a setup intent) and saving
 * one are the two a person who wants to find out whether a stolen card number is good would use over and over ("card testing"), so
 * they have an hourly limit as well; choosing a card, removing one, cancelling and resuming share the billing limit of ten a minute
 * with starting a subscription and opening the portal.
 *
 * With no Stripe secret (and in tests) these actions answer "success" and send nothing, so what is checked here is the limit, not the call.
 * Stripe's HTTP layer is replaced by one that knows no route, so a call that did go out would fail the test (the key in .env is a real one).
 */
class StripeCallsRateLimitedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private RoutedStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake']);
        ApiRequestor::setHttpClient($this->stripe = new RoutedStripeHttpClient([]));

        // No Stripe id: Cashier asks Stripe about a customer it knows by one, even to say there is no default card.
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->stripe->requests, 'Something called Stripe.');

        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    private function intent(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->postJson(route('settings.billing.intent'));
    }

    private function save(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->postJson(route('settings.billing.payment-methods.store'), ['payment_method' => 'pm_card']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function billingActions(): array
    {
        return [
            'choosing the card that is used' => ['put', 'settings.billing.payment-methods.default', ['paymentMethod' => 'pm_card']],
            'removing a card' => ['delete', 'settings.billing.payment-methods.destroy', ['paymentMethod' => 'pm_card']],
            'cancelling the subscription' => ['post', 'settings.billing.cancel', []],
            'resuming the subscription' => ['post', 'settings.billing.resume', []],
        ];
    }

    #[Test]
    public function making_a_card_ready_is_limited_to_ten_a_minute(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->intent()->assertOk();
        }

        $this->intent()->assertTooManyRequests();
    }

    #[Test]
    public function saving_a_card_is_limited_to_ten_a_minute(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->save()->assertCreated();
        }

        $this->save()->assertTooManyRequests();
    }

    #[Test]
    public function making_a_card_ready_and_saving_one_share_the_allowance(): void
    {
        // A person adding a card makes one ready and saves one, so the two count together: neither is a way round the other's limit.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->intent()->assertOk();
            $this->save()->assertCreated();
        }

        $this->intent()->assertTooManyRequests();
        $this->save()->assertTooManyRequests();
    }

    #[Test]
    public function the_allowance_for_adding_a_card_is_ten_a_minute_and_sixty_an_hour(): void
    {
        $limits = RateLimiter::limiter('card-setup')(Request::create('/', 'POST'));

        $this->assertSame([[10, 60], [60, 3600]], collect($limits)->map(fn ($limit) => [$limit->maxAttempts, $limit->decaySeconds])->all());
    }

    #[Test]
    public function a_card_tester_who_stays_under_the_limit_for_a_minute_is_stopped_at_sixty_in_the_hour(): void
    {
        // Ten a minute, a minute apart: never over the per-minute limit, which is what somebody trying to stay quiet would do.
        for ($minute = 1; $minute <= 6; $minute++) {
            for ($attempt = 1; $attempt <= 10; $attempt++) {
                $this->intent()->assertOk();
            }

            $this->travel(61)->seconds();
        }

        $this->intent()->assertTooManyRequests();
        $this->save()->assertTooManyRequests();

        // The hour is over.
        $this->travel(61)->minutes();

        $this->intent()->assertOk();
    }

    #[Test]
    public function the_hourly_limit_is_a_members_own(): void
    {
        for ($minute = 1; $minute <= 6; $minute++) {
            for ($attempt = 1; $attempt <= 10; $attempt++) {
                $this->intent()->assertOk();
            }

            $this->travel(61)->seconds();
        }

        $this->intent()->assertTooManyRequests();

        // Somebody else has used none of their sixty.
        $this->intent(User::factory()->create())->assertOk();
    }

    #[Test]
    public function the_minute_is_over_after_a_minute(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->intent()->assertOk();
        }

        $this->intent()->assertTooManyRequests();

        $this->travel(61)->seconds();

        $this->intent()->assertOk();
    }

    #[Test]
    public function a_member_is_counted_alone(): void
    {
        for ($attempt = 1; $attempt <= 11; $attempt++) {
            $this->intent();
        }

        $this->intent()->assertTooManyRequests();
        $this->intent(User::factory()->create())->assertOk();
    }

    #[Test]
    #[DataProvider('billingActions')]
    public function choosing_removing_cancelling_and_resuming_are_limited_to_ten_a_minute(string $method, string $route, array $parameters): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->assertNotSame(429, $this->actingAs($this->user)->json($method, route($route, $parameters))->getStatusCode(), "Attempt {$attempt} was refused.");
        }

        $this->actingAs($this->user)->json($method, route($route, $parameters))->assertTooManyRequests();
    }

    #[Test]
    public function they_share_the_ten_a_minute_with_starting_a_subscription(): void
    {
        $plan = SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_pro']);

        // Nine refused attempts to start a subscription (no payment method), then one of each of the others.
        for ($attempt = 1; $attempt <= 9; $attempt++) {
            $this->actingAs($this->user)->postJson(route('settings.billing.subscribe'), ['plan_id' => $plan->id])->assertStatus(422);
        }

        $this->assertNotSame(429, $this->actingAs($this->user)->deleteJson(route('settings.billing.payment-methods.destroy', 'pm_card'))->getStatusCode());

        $this->actingAs($this->user)->postJson(route('settings.billing.cancel'))->assertTooManyRequests();
        $this->actingAs($this->user)->postJson(route('settings.billing.resume'))->assertTooManyRequests();
        $this->actingAs($this->user)->putJson(route('settings.billing.payment-methods.default', 'pm_card'))->assertTooManyRequests();
    }

    #[Test]
    public function adding_a_card_does_not_use_up_the_billing_allowance_and_the_reverse(): void
    {
        // Two limits of their own: a person who has made ten cards ready can still cancel, and one who has cancelled ten times can still add a card.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->intent()->assertOk();
        }

        $this->assertNotSame(429, $this->actingAs($this->user)->postJson(route('settings.billing.cancel'))->getStatusCode());

        $other = User::factory()->create();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($other)->postJson(route('settings.billing.cancel'));
        }

        $this->actingAs($other)->postJson(route('settings.billing.cancel'))->assertTooManyRequests();
        $this->intent($other)->assertOk();
    }

    #[Test]
    public function the_refusal_says_how_long_to_wait(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->intent();
        }

        $this->intent()->assertTooManyRequests()->assertHeader('Retry-After');
    }
}
