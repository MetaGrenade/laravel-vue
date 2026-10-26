<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Support\Billing\SubscriptionCancellationFailed;
use App\Support\Billing\SubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\HttpClient\CurlClient;
use Tests\Support\ScriptedStripeHttpClient as Stripe;
use Tests\TestCase;

/**
 * Deleting an account must end its Stripe subscriptions first, or the card keeps being charged for an account that no
 * longer exists. The real SDK and Cashier run against scripted answers, so what is asked of Stripe is checked too.
 */
class AccountDeletionSubscriptionsTest extends TestCase
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
     * A user with a Stripe customer and a local copy of each of the given subscriptions.
     */
    private function subscribed(string $customerId, string ...$subscriptionIds): User
    {
        $user = User::factory()->create(['stripe_id' => $customerId]);

        foreach ($subscriptionIds as $subscriptionId) {
            $user->subscriptions()->create([
                'type' => 'default',
                'stripe_id' => $subscriptionId,
                'stripe_status' => 'active',
                'stripe_price' => 'price_pro',
                'quantity' => 1,
            ]);
        }

        return $user;
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * What was asked of Stripe, in order, as "METHOD /path".
     *
     * @return list<string>
     */
    private function calls(Stripe $client): array
    {
        return array_map(
            fn (array $request) => strtoupper($request['method']).' '.parse_url($request['url'], PHP_URL_PATH),
            $client->requests,
        );
    }

    private function localStatus(string $subscriptionId): ?string
    {
        return Subscription::query()->where('stripe_id', $subscriptionId)->value('stripe_status');
    }

    #[Test]
    public function deleting_your_account_ends_your_subscriptions_at_stripe_first(): void
    {
        $user = $this->subscribed('cus_1', 'sub_1', 'sub_2');
        $client = $this->stripeAnswers([
            Stripe::subscriptions([Stripe::subscription('sub_1'), Stripe::subscription('sub_2', 'trialing')]),
            [200, Stripe::subscription('sub_1', 'canceled')],
            [200, Stripe::subscription('sub_2', 'canceled')],
        ]);

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_1', 'DELETE /v1/subscriptions/sub_2'], $this->calls($client));
        $this->assertSame('cus_1', $client->requests[0]['params']['customer']);

        $this->assertGuest();
        $this->assertNull($user->fresh());

        foreach (['sub_1', 'sub_2'] as $subscriptionId) {
            $this->assertSame('canceled', $this->localStatus($subscriptionId));
            $this->assertTrue(Subscription::query()->where('stripe_id', $subscriptionId)->firstOrFail()->ended(), 'it ends now, not later');
        }
    }

    #[Test]
    public function the_subscription_is_ended_now_without_a_proration_and_the_customer_is_kept(): void
    {
        $user = $this->subscribed('cus_1', 'sub_1');
        $client = $this->stripeAnswers([
            Stripe::subscriptions([Stripe::subscription('sub_1')]),
            [200, Stripe::subscription('sub_1', 'canceled')],
        ]);

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

        // Cancelling is the immediate form (a DELETE), never "cancel at the end of the period" (an update).
        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_1'], $this->calls($client));
        $this->assertContains($client->requests[1]['params']['prorate'], [false, 'false']);
        $this->assertArrayNotHasKey('cancel_at_period_end', $client->requests[1]['params']);

        // Its invoices are what tax and bookkeeping are checked against: the customer is not deleted.
        foreach ($this->calls($client) as $call) {
            $this->assertStringNotContainsString('/v1/customers', $call);
        }
    }

    #[Test]
    public function a_subscription_already_set_to_end_with_the_period_is_ended_now(): void
    {
        $user = $this->subscribed('cus_1', 'sub_1');
        Subscription::query()->where('stripe_id', 'sub_1')->update(['ends_at' => now()->addDays(20)]);
        $client = $this->stripeAnswers([
            Stripe::subscriptions([Stripe::subscription('sub_1', 'active', cancelAtPeriodEnd: true)]),
            [200, Stripe::subscription('sub_1', 'canceled')],
        ]);

        app(SubscriptionManager::class)->deleteUser($user);

        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_1'], $this->calls($client));
        $this->assertTrue(Subscription::query()->where('stripe_id', 'sub_1')->firstOrFail()->ends_at->lte(now()), 'the end date moves up to now');
    }

    #[Test]
    public function every_subscription_that_is_not_over_is_ended_and_one_that_expired_unpaid_is_left_alone(): void
    {
        $user = $this->subscribed('cus_1');
        $statuses = ['active', 'trialing', 'past_due', 'incomplete', 'unpaid', 'paused', 'incomplete_expired'];
        $client = $this->stripeAnswers([
            Stripe::subscriptions(array_map(fn (string $status) => Stripe::subscription("sub_{$status}", $status), $statuses)),
            ...array_fill(0, 6, [200, Stripe::subscription('sub_x', 'canceled')]),
        ]);

        app(SubscriptionManager::class)->deleteUser($user);

        $this->assertSame([
            'GET /v1/subscriptions',
            'DELETE /v1/subscriptions/sub_active',
            'DELETE /v1/subscriptions/sub_trialing',
            'DELETE /v1/subscriptions/sub_past_due',
            'DELETE /v1/subscriptions/sub_incomplete',
            'DELETE /v1/subscriptions/sub_unpaid',
            'DELETE /v1/subscriptions/sub_paused',
        ], $this->calls($client));
    }

    #[Test]
    public function what_stripe_says_is_live_decides_not_the_local_copy_which_may_be_behind(): void
    {
        // The webhook that records a subscription never arrived: Stripe has one the table does not, and the table
        // still shows one as active that Stripe has already cancelled.
        $user = $this->subscribed('cus_1', 'sub_stale');
        $client = $this->stripeAnswers([
            Stripe::subscriptions([Stripe::subscription('sub_unrecorded')]),
            [200, Stripe::subscription('sub_unrecorded', 'canceled')],
        ]);

        app(SubscriptionManager::class)->deleteUser($user);

        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_unrecorded'], $this->calls($client));
        $this->assertSame('active', $this->localStatus('sub_stale'), 'a row Stripe does not list is not touched');
    }

    #[Test]
    public function every_page_of_subscriptions_is_read_before_any_is_ended(): void
    {
        $user = $this->subscribed('cus_1');
        $first = array_map(fn (int $n) => Stripe::subscription("sub_{$n}"), range(1, 100));
        $second = [Stripe::subscription('sub_101')];
        $client = $this->stripeAnswers([
            Stripe::subscriptions($first, hasMore: true),
            Stripe::subscriptions($second),
            ...array_fill(0, 101, [200, Stripe::subscription('sub_x', 'canceled')]),
        ]);

        app(SubscriptionManager::class)->deleteUser($user);

        $calls = $this->calls($client);
        $this->assertCount(103, $calls);
        $this->assertSame(['GET /v1/subscriptions', 'GET /v1/subscriptions'], array_slice($calls, 0, 2), 'both pages are read first');
        $this->assertSame('sub_100', $client->requests[1]['params']['starting_after']);
        $this->assertSame('DELETE /v1/subscriptions/sub_1', $calls[2]);
        $this->assertSame('DELETE /v1/subscriptions/sub_101', $calls[102], 'a subscription on the second page is ended too');
    }

    #[Test]
    public function a_user_who_never_reached_stripe_is_deleted_without_contacting_it(): void
    {
        $user = User::factory()->create();
        $client = $this->stripeAnswers([]);

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertSame([], $client->requests);
        $this->assertNull($user->fresh());
    }

    #[Test]
    public function a_user_with_a_customer_and_nothing_live_is_deleted(): void
    {
        $user = $this->subscribed('cus_1', 'sub_old');
        Subscription::query()->update(['stripe_status' => 'canceled', 'ends_at' => now()->subMonth()]);
        $client = $this->stripeAnswers([Stripe::subscriptions([])]);

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertSame(['GET /v1/subscriptions'], $this->calls($client));
        $this->assertNull($user->fresh());
    }

    #[Test]
    public function the_wrong_password_does_not_touch_stripe(): void
    {
        $user = $this->subscribed('cus_1', 'sub_1');
        $client = $this->stripeAnswers([]);

        $this->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->assertSame([], $client->requests, 'nothing is cancelled for a request that is refused');
        $this->assertNotNull($user->fresh());
        $this->assertSame('active', $this->localStatus('sub_1'));
    }

    /**
     * @return array<string, array{0: list<mixed>}>
     */
    public static function stripeFailures(): array
    {
        $listed = Stripe::subscriptions([Stripe::subscription('sub_1')]);

        return [
            'cannot be reached' => [[new ApiConnectionException('Could not connect to Stripe.')]],
            'fails listing' => [[Stripe::error(500, 'Something went wrong on our end.', 'api_error')]],
            'rejects the key' => [[Stripe::error(401, 'Invalid API Key provided.', 'authentication_error')]],
            'rate limits the listing' => [[Stripe::error(429, 'Too many requests.', 'rate_limit_error')]],
            'answers with something it cannot read' => [[new UnexpectedValueException('Invalid response body from API.')]],
            'a 404 that is not a missing resource' => [[Stripe::error(404, 'Not found.')]],
            'fails ending it' => [[$listed, Stripe::error(500, 'Something went wrong on our end.', 'api_error')]],
            'drops the connection while ending it' => [[$listed, new ApiConnectionException('Connection reset.')]],
            'refuses to end it' => [[$listed, Stripe::error(400, 'This subscription cannot be cancelled.')]],
        ];
    }

    /**
     * @param  list<mixed>  $answers
     */
    #[Test]
    #[DataProvider('stripeFailures')]
    public function when_stripe_fails_the_account_is_kept_and_stays_signed_in(array $answers): void
    {
        Log::spy();
        $user = $this->subscribed('cus_1', 'sub_1');
        $this->stripeAnswers($answers);

        $this->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'has not been deleted'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
        $this->assertSame('active', $this->localStatus('sub_1'), 'what is not known to be ended is not recorded as ended');
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function a_failure_part_way_keeps_what_was_ended_and_trying_again_finishes_the_rest(): void
    {
        Log::spy();
        $user = $this->subscribed('cus_1', 'sub_1', 'sub_2');
        $this->stripeAnswers([
            Stripe::subscriptions([Stripe::subscription('sub_1'), Stripe::subscription('sub_2')]),
            [200, Stripe::subscription('sub_1', 'canceled')],
            Stripe::error(500, 'Something went wrong on our end.', 'api_error'),
        ]);

        $this->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertSessionHas('error');

        $this->assertNotNull($user->fresh());
        $this->assertSame('canceled', $this->localStatus('sub_1'));
        $this->assertSame('active', $this->localStatus('sub_2'));

        // Stripe no longer lists the one that was ended, so the second attempt asks only for the other.
        $client = $this->stripeAnswers([
            Stripe::subscriptions([Stripe::subscription('sub_2')]),
            [200, Stripe::subscription('sub_2', 'canceled')],
        ]);

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_2'], $this->calls($client));
        $this->assertNull($user->fresh());
        $this->assertSame('canceled', $this->localStatus('sub_2'));
    }

    #[Test]
    public function a_customer_stripe_does_not_know_does_not_make_the_account_undeletable(): void
    {
        Log::spy();
        $user = $this->subscribed('cus_gone', 'sub_1');
        $client = $this->stripeAnswers([Stripe::error(400, 'No such customer: cus_gone', 'invalid_request_error', 'resource_missing')]);

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertSame(['GET /v1/subscriptions'], $this->calls($client));
        $this->assertNull($user->fresh());
        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function a_subscription_stripe_no_longer_knows_is_skipped_and_the_others_are_still_ended(): void
    {
        Log::spy();
        $user = $this->subscribed('cus_1', 'sub_1', 'sub_2');
        $client = $this->stripeAnswers([
            Stripe::subscriptions([Stripe::subscription('sub_1'), Stripe::subscription('sub_2')]),
            Stripe::error(404, 'No such subscription: sub_1', 'invalid_request_error', 'resource_missing'),
            [200, Stripe::subscription('sub_2', 'canceled')],
        ]);

        app(SubscriptionManager::class)->deleteUser($user);

        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_1', 'DELETE /v1/subscriptions/sub_2'], $this->calls($client));
        $this->assertSame('canceled', $this->localStatus('sub_1'));
        $this->assertSame('canceled', $this->localStatus('sub_2'));
    }

    #[Test]
    public function the_failure_is_raised_with_stripes_error_behind_it(): void
    {
        Log::spy();
        $user = $this->subscribed('cus_1', 'sub_1');
        $this->stripeAnswers([Stripe::error(500, 'Something went wrong on our end.', 'api_error')]);

        try {
            app(SubscriptionManager::class)->deleteUser($user);
            $this->fail('A failure to end a subscription must not pass silently.');
        } catch (SubscriptionCancellationFailed $exception) {
            $this->assertStringContainsString('Something went wrong on our end.', $exception->getPrevious()?->getMessage() ?? '');
        }

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['user_id'] === $user->id && $context['stripe_customer'] === 'cus_1');
    }

    #[Test]
    public function an_admin_deleting_a_user_ends_their_subscriptions_first(): void
    {
        $user = $this->subscribed('cus_1', 'sub_1');
        $client = $this->stripeAnswers([
            Stripe::subscriptions([Stripe::subscription('sub_1')]),
            [200, Stripe::subscription('sub_1', 'canceled')],
        ]);

        $this->actingAs($this->admin())
            ->from(route('acp.users.index'))
            ->delete(route('acp.users.destroy', $user))
            ->assertRedirect(route('acp.users.index'))
            ->assertSessionHas('success', 'User deleted.');

        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_1'], $this->calls($client));
        $this->assertNull($user->fresh());
        $this->assertSame('canceled', $this->localStatus('sub_1'));
    }

    #[Test]
    public function an_admin_cannot_delete_a_user_whose_subscription_could_not_be_ended(): void
    {
        Log::spy();
        $user = $this->subscribed('cus_1', 'sub_1');
        $this->stripeAnswers([new ApiConnectionException('Could not connect to Stripe.')]);

        $this->actingAs($this->admin())
            ->from(route('acp.users.index'))
            ->delete(route('acp.users.destroy', $user))
            ->assertRedirect(route('acp.users.index'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, $user->nickname) && str_contains($message, 'not deleted'))
            ->assertSessionMissing('success');

        $this->assertNotNull($user->fresh());
        $this->assertSame('active', $this->localStatus('sub_1'));
    }

    #[Test]
    public function an_admin_deleting_a_user_with_no_customer_does_not_contact_stripe(): void
    {
        $user = User::factory()->create();
        $client = $this->stripeAnswers([]);

        $this->actingAs($this->admin())->delete(route('acp.users.destroy', $user))->assertSessionHas('success', 'User deleted.');

        $this->assertSame([], $client->requests);
        $this->assertNull($user->fresh());
    }

    #[Test]
    public function a_bulk_delete_ends_subscriptions_and_leaves_alone_only_those_stripe_would_not_end(): void
    {
        Log::spy();
        $subscribed = $this->subscribed('cus_ok', 'sub_ok');
        $plain = User::factory()->create();
        $failing = $this->subscribed('cus_bad', 'sub_bad');
        $client = $this->stripeAnswers([
            // The users go in order: the first is ended, the second has no customer, the third cannot be reached.
            Stripe::subscriptions([Stripe::subscription('sub_ok')]),
            [200, Stripe::subscription('sub_ok', 'canceled')],
            Stripe::error(500, 'Something went wrong on our end.', 'api_error'),
        ]);

        $this->actingAs($this->admin())
            ->from(route('acp.users.index'))
            ->patch(route('acp.users.bulk-update'), ['action' => 'delete', 'ids' => [$subscribed->id, $plain->id, $failing->id]])
            ->assertRedirect(route('acp.users.index'))
            ->assertSessionHas('success', 'Deleted 2 users.')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, $failing->nickname)
                && ! str_contains($message, $subscribed->nickname)
                && str_contains($message, 'Stripe'));

        $this->assertSame(['GET /v1/subscriptions', 'DELETE /v1/subscriptions/sub_ok', 'GET /v1/subscriptions'], $this->calls($client));
        $this->assertNull($subscribed->fresh());
        $this->assertNull($plain->fresh());
        $this->assertNotNull($failing->fresh());
        $this->assertSame('canceled', $this->localStatus('sub_ok'));
        $this->assertSame('active', $this->localStatus('sub_bad'));
    }

    #[Test]
    public function a_bulk_delete_names_only_the_first_few_users_it_could_not_delete(): void
    {
        Log::spy();
        $users = collect(range(1, 6))->map(function (int $n) {
            $user = $this->subscribed("cus_{$n}", "sub_{$n}");
            $user->forceFill(['nickname' => "Undeleted member {$n}"])->save();

            return $user;
        });
        $this->stripeAnswers(array_fill(0, 6, Stripe::error(500, 'Something went wrong on our end.', 'api_error')));

        $this->actingAs($this->admin())
            ->from(route('acp.users.index'))
            ->patch(route('acp.users.bulk-update'), ['action' => 'delete', 'ids' => $users->pluck('id')->all()])
            ->assertSessionHas('success', 'No users were deleted.')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, $users[4]->nickname)
                && ! str_contains($message, $users[5]->nickname)
                && str_contains($message, 'and 1 more'));

        $this->assertSame(6, User::query()->whereIn('id', $users->pluck('id'))->count());
    }
}
