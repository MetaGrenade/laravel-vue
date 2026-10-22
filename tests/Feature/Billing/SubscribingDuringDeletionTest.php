<?php

namespace Tests\Feature\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Billing\SubscriptionCancellationFailed;
use App\Support\Billing\SubscriptionManager;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\HttpKernel\Exception\LockedHttpException;
use Tests\Support\ScriptedStripeHttpClient as Stripe;
use Tests\TestCase;

/**
 * Starting a subscription and deleting the account are never done at the same time for one user. A subscription started
 * after deletion has listed what is live would otherwise survive the deletion and go on charging with nobody to ask.
 *
 * One process cannot run two requests at once, so the second request is made from inside the first's call to Stripe,
 * the moment the other holds the lock.
 */
class SubscribingDuringDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nobody waits in a test: a lock that is held is refused at once.
        config(['cashier.secret' => 'sk_test_fake', 'billing.locks.wait' => 0]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function stripeAnswers(array $responses): Stripe
    {
        $client = new Stripe($responses);
        ApiRequestor::setHttpClient($client);

        return $client;
    }

    /**
     * A Stripe stand-in that runs a callback when the first request arrives, as another request would at that moment.
     *
     * @param  list<mixed>  $responses
     */
    private function stripeAnswersAndThen(array $responses, Closure $meanwhile): Stripe
    {
        $client = new class($responses) extends Stripe
        {
            public ?Closure $meanwhile = null;

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                if ($this->meanwhile !== null) {
                    $callback = $this->meanwhile;
                    $this->meanwhile = null;
                    $callback();
                }

                return parent::request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);
            }
        };
        $client->meanwhile = $meanwhile;
        ApiRequestor::setHttpClient($client);

        return $client;
    }

    /**
     * @return list<string>
     */
    private function calls(Stripe $client): array
    {
        return array_map(
            fn (array $request) => strtoupper($request['method']).' '.parse_url($request['url'], PHP_URL_PATH),
            $client->requests,
        );
    }

    private function lockName(User $user): string
    {
        return 'billing:user:'.$user->id;
    }

    #[Test]
    public function a_subscription_started_while_the_account_is_being_deleted_is_refused_before_it_reaches_stripe(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_1']);
        $plan = SubscriptionPlan::factory()->create();
        $refusal = null;

        $client = $this->stripeAnswersAndThen(
            [Stripe::subscriptions([Stripe::subscription('sub_1')]), [200, Stripe::subscription('sub_1', 'canceled')]],
            function () use ($user, $plan, &$refusal) {
                try {
                    app(SubscriptionManager::class)->create($user, $plan, 'pm_card');
                } catch (LockedHttpException $exception) {
                    $refusal = $exception;
                }
            },
        );

        app(SubscriptionManager::class)->deleteUser($user);

        $this->assertInstanceOf(LockedHttpException::class, $refusal, 'the subscription must wait for the deletion, not run beside it');
        $this->assertSame(423, $refusal->getStatusCode());
        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_1'], $this->calls($client), 'nothing was asked of Stripe for it');
        $this->assertNull($user->fresh());
    }

    #[Test]
    public function an_account_cannot_be_deleted_while_a_subscription_is_being_started(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_1']);
        $client = $this->stripeAnswers([Stripe::subscriptions([])]);
        $lock = Cache::lock($this->lockName($user), 60);
        $this->assertTrue($lock->get(), 'standing in for the request that is starting the subscription');

        try {
            app(SubscriptionManager::class)->deleteUser($user);
            $this->fail('A deletion that cannot be sure of the subscriptions must not go ahead.');
        } catch (SubscriptionCancellationFailed) {
            // Refused, and nothing was asked of Stripe or deleted.
        }

        $this->assertSame([], $client->requests);
        $this->assertNotNull($user->fresh());

        $lock->release();
        app(SubscriptionManager::class)->deleteUser($user);

        $this->assertSame(['GET /v1/subscriptions'], $this->calls($client), 'once the other request is done, the deletion goes ahead');
        $this->assertNull($user->fresh());
    }

    #[Test]
    public function the_profile_page_keeps_the_account_and_the_session_when_a_subscription_is_being_started(): void
    {
        $user = User::factory()->create();
        $this->stripeAnswers([]);
        $this->assertTrue(Cache::lock($this->lockName($user), 60)->get());

        $this->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'has not been deleted'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    #[Test]
    public function a_subscription_started_before_the_deletion_is_seen_even_by_a_user_model_that_predates_it(): void
    {
        // The model in hand has no customer: another request has given the account one since it was loaded.
        $stale = User::factory()->create();
        User::query()->whereKey($stale->id)->update(['stripe_id' => 'cus_late']);
        $client = $this->stripeAnswers([
            Stripe::subscriptions([Stripe::subscription('sub_late')]),
            [200, Stripe::subscription('sub_late', 'canceled')],
        ]);

        app(SubscriptionManager::class)->deleteUser($stale);

        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_late'], $this->calls($client));
        $this->assertSame('cus_late', $client->requests[0]['params']['customer']);
        $this->assertNull(User::query()->find($stale->id));
    }

    #[Test]
    public function a_subscription_cannot_be_started_for_an_account_that_is_already_gone(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();
        $client = $this->stripeAnswers([]);
        User::query()->whereKey($user->id)->delete();

        try {
            app(SubscriptionManager::class)->create($user, $plan, 'pm_card');
            $this->fail('An account that no longer exists must not be given a subscription.');
        } catch (AuthenticationException) {
            // Refused.
        }

        $this->assertSame([], $client->requests);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function subscribeRoutes(): array
    {
        return [
            'the pricing page' => ['pricing.subscribe'],
            'the billing settings' => ['settings.billing.subscribe'],
        ];
    }

    #[Test]
    #[DataProvider('subscribeRoutes')]
    public function a_subscribe_request_that_was_in_flight_when_the_account_was_deleted_is_refused(string $route): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();
        $client = $this->stripeAnswers([]);
        $this->actingAs($user);
        // Deleted by another request after this one was signed in.
        User::query()->whereKey($user->id)->delete();

        $this->postJson(route($route), ['plan_id' => $plan->id, 'payment_method' => 'pm_card'])->assertUnauthorized();

        $this->assertSame([], $client->requests);
    }

    #[Test]
    #[DataProvider('subscribeRoutes')]
    public function a_subscribe_request_that_has_to_wait_too_long_is_told_to_try_again(string $route): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();
        $client = $this->stripeAnswers([]);
        $this->assertTrue(Cache::lock($this->lockName($user), 60)->get(), 'standing in for the deletion');

        $this->actingAs($user)
            ->postJson(route($route), ['plan_id' => $plan->id, 'payment_method' => 'pm_card'])
            ->assertStatus(423)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'try again'));

        $this->assertSame([], $client->requests);
    }

    #[Test]
    public function starting_a_subscription_runs_once_the_lock_is_held_and_lets_go_of_it_when_it_fails(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();
        $client = $this->stripeAnswers([Stripe::error(500, 'Something went wrong on our end.', 'api_error')]);

        try {
            app(SubscriptionManager::class)->create($user, $plan, 'pm_card');
            $this->fail('The failure must reach the caller.');
        } catch (ApiErrorException) {
            // Cashier's first step, creating the customer, is what failed.
        }

        $this->assertSame(['POST /v1/customers'], $this->calls($client));

        $lock = Cache::lock($this->lockName($user), 60);
        $this->assertTrue($lock->get(), 'the lock is not left held by a failed subscription');
        $lock->release();
    }

    #[Test]
    public function the_lock_is_let_go_after_a_deletion_that_failed_and_after_one_that_succeeded(): void
    {
        $failing = User::factory()->create(['stripe_id' => 'cus_1']);
        $this->stripeAnswers([Stripe::error(500, 'Something went wrong on our end.', 'api_error')]);

        try {
            app(SubscriptionManager::class)->deleteUser($failing);
            $this->fail('The failure must reach the caller.');
        } catch (SubscriptionCancellationFailed) {
            // Expected.
        }

        $lock = Cache::lock($this->lockName($failing), 60);
        $this->assertTrue($lock->get(), 'the lock is not left held by a failed deletion');
        $lock->release();

        $this->stripeAnswers([Stripe::subscriptions([])]);
        app(SubscriptionManager::class)->deleteUser($failing);

        $lock = Cache::lock($this->lockName($failing), 60);
        $this->assertTrue($lock->get(), 'nor by one that went through');
        $lock->release();
    }

    #[Test]
    public function one_users_lock_does_not_hold_up_another_user(): void
    {
        $busy = User::factory()->create();
        $other = User::factory()->create();
        $this->stripeAnswers([]);
        $this->assertTrue(Cache::lock($this->lockName($busy), 60)->get());

        app(SubscriptionManager::class)->deleteUser($other);

        $this->assertNull($other->fresh());
        $this->assertNotNull($busy->fresh());
    }
}
