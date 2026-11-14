<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Jobs\ExpireOrders;
use App\Jobs\ExpirePendingOrders;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\PendingOrderExpirer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;
use TypeError;

/**
 * Orders whose payment window has run out are expired by many short jobs, not one long one (docs/commerce.md).
 *
 * It was one job that asked the payment provider about every such order in turn, from the lowest id, so the worker's 60 seconds cut it off
 * at some hundreds of orders and the same first ones were asked again every five minutes: with a few hundred orders the provider could not
 * answer about (keys changed, a provider switched off) no order behind them was ever cancelled, and each of them was reported every run.
 */
class PendingOrderExpiryFanOutTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
    }

    /**
     * An order whose window ran out, with a payment that is still pending. When the provider is "askable" it says the checkout expired;
     * otherwise it has never heard of it, as Stripe has not of a session made with other keys.
     */
    private function dueOrder(bool $askable = true): Order
    {
        $order = Order::factory()->create(['expires_at' => now()->subMinutes(5)]);
        $reference = 'cs_test_due_'.$order->id;

        Payment::factory()->create(['order_id' => $order->id, 'provider_reference' => $reference]);

        if ($askable) {
            $this->stripe->sessions[$reference] = [
                'id' => $reference,
                'object' => 'checkout.session',
                'status' => 'expired',
                'payment_status' => 'unpaid',
                'payment_intent' => null,
            ];
        }

        return $order;
    }

    /**
     * @return list<string> The checkout sessions the provider was asked about, in order.
     */
    private function asked(): array
    {
        return collect($this->stripe->calls)
            ->filter(fn (string $call): bool => str_starts_with($call, 'retrieve:'))
            ->map(fn (string $call): string => substr($call, strlen('retrieve:')))
            ->values()
            ->all();
    }

    /**
     * @return list<list<int>>
     */
    private function queuedChunks(): array
    {
        return Queue::pushed(ExpireOrders::class)->map(fn (ExpireOrders $job): array => $job->orderIds)->values()->all();
    }

    #[Test]
    public function the_schedule_queues_a_job_for_each_twenty_five_due_orders_lowest_id_first(): void
    {
        Queue::fake();

        $due = collect(range(1, 60))->map(fn () => $this->dueOrder());

        // Not due: still inside the window, no window at all, cancelled, and paid.
        Order::factory()->create(['expires_at' => now()->addMinutes(10)]);
        Order::factory()->create(['expires_at' => null]);
        Order::factory()->create(['expires_at' => now()->subMinute(), 'status' => OrderStatus::Cancelled]);
        Order::factory()->paid()->create(['expires_at' => now()->subMinute()]);

        (new ExpirePendingOrders)->handle(app(PendingOrderExpirer::class));

        $chunks = $this->queuedChunks();

        $this->assertSame([25, 25, 10], array_map('count', $chunks));
        $this->assertSame($due->pluck('id')->all(), array_merge(...$chunks));

        // It only queues: nobody was asked, and nothing was cancelled, until the jobs it queued run.
        $this->assertSame([], $this->stripe->calls);
        $this->assertSame(60, Order::query()->where('status', OrderStatus::Pending->value)->where('expires_at', '<=', now())->count());
    }

    #[Test]
    public function a_run_with_nothing_due_queues_nothing(): void
    {
        Queue::fake();

        Order::factory()->create(['expires_at' => now()->addMinutes(10)]);

        (new ExpirePendingOrders)->handle(app(PendingOrderExpirer::class));

        Queue::assertNotPushed(ExpireOrders::class);
    }

    #[Test]
    public function the_queued_jobs_use_the_default_queue_and_cancel_every_due_order_and_no_other_when_they_run(): void
    {
        Queue::fake();

        $due = collect(range(1, 30))->map(fn () => $this->dueOrder());
        $fresh = Order::factory()->create(['expires_at' => now()->addMinutes(10)]);
        $paid = Order::factory()->paid()->create(['expires_at' => now()->subMinute()]);

        (new ExpirePendingOrders)->handle(app(PendingOrderExpirer::class));

        Queue::assertPushed(ExpireOrders::class, 2);
        Queue::assertPushed(ExpireOrders::class, fn (ExpireOrders $job): bool => $job->queue === null && $job->connection === null);

        // The jobs it queued, run in turn as a worker would.
        Queue::pushed(ExpireOrders::class)->each(fn (ExpireOrders $job) => $job->handle(app(PendingOrderExpirer::class)));

        $this->assertSame(30, Order::query()->whereKey($due->pluck('id'))->where('status', OrderStatus::Cancelled->value)->count());
        $this->assertSame(OrderStatus::Pending, $fresh->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $paid->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Paid, $paid->fresh()->payment_status);
    }

    #[Test]
    public function the_list_of_due_orders_is_read_a_page_at_a_time_in_id_order(): void
    {
        $due = collect(range(1, 7))->map(fn () => $this->dueOrder());

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $expirer = app(PendingOrderExpirer::class);
        $first = $expirer->dueOrderIds(0, 3);
        $second = $expirer->dueOrderIds(max($first), 3);

        $this->assertSame($due->take(3)->pluck('id')->all(), $first);
        $this->assertSame($due->slice(3, 3)->pluck('id')->all(), $second);
        $this->assertSame([], $expirer->dueOrderIds($due->last()->id, 3));

        // A database that happens to read an index in order gives the right answer without the ORDER BY, and the walk (everything above
        // the highest id of the last page) depends on it: so it is pinned in the SQL, with the limit.
        $this->assertCount(3, $queries);
        $this->assertMatchesRegularExpression('/order by\s+["`]?id["`]?\s+asc\s+limit\s+3/i', $queries[0]);
    }

    #[Test]
    public function a_job_that_runs_twice_asks_nobody_about_an_order_that_is_settled(): void
    {
        Queue::fake();

        $orders = collect(range(1, 3))->map(fn () => $this->dueOrder());
        $job = new ExpireOrders($orders->pluck('id')->all());

        $job->handle(app(PendingOrderExpirer::class));
        $this->assertCount(3, $this->asked());

        // The worker gave it again (a retry, or the next run of the schedule queued the same orders).
        $job->handle(app(PendingOrderExpirer::class));

        $this->assertCount(3, $this->asked(), 'a cancelled order is not asked about again');
        $this->assertSame(3, Order::query()->where('status', OrderStatus::Cancelled->value)->count());
    }

    #[Test]
    public function an_order_that_is_not_due_is_left_alone_even_when_a_job_names_it(): void
    {
        Queue::fake();

        $fresh = Order::factory()->create(['expires_at' => now()->addMinutes(10)]);
        $paid = Order::factory()->paid()->create(['expires_at' => now()->subMinute()]);
        $never = Order::factory()->create(['expires_at' => null]);

        $result = app(PendingOrderExpirer::class)->expireOrders([$fresh->id, $paid->id, $never->id]);

        $this->assertSame(['cancelled' => 0, 'paid' => 0, 'skipped' => 0], $result);
        $this->assertSame(OrderStatus::Pending, $fresh->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $paid->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $never->fresh()->status);
        $this->assertSame([], $this->stripe->calls);
    }

    #[Test]
    public function an_order_the_provider_cannot_be_asked_about_does_not_stop_the_orders_after_it(): void
    {
        Queue::fake();
        Exceptions::fake();

        $stuck = $this->dueOrder(askable: false);
        $second = $this->dueOrder();
        $third = $this->dueOrder();

        $result = app(PendingOrderExpirer::class)->expireOrders([$stuck->id, $second->id, $third->id]);

        $this->assertSame(['cancelled' => 2, 'paid' => 0, 'skipped' => 1], $result);
        $this->assertSame(OrderStatus::Pending, $stuck->fresh()->status, 'it may have been paid: it is left as it was');
        $this->assertSame(OrderStatus::Cancelled, $second->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $third->fresh()->status);

        Exceptions::assertReportedCount(1);

        // In id order, which is what the schedule queued them in.
        $this->assertSame(['cs_test_due_'.$stuck->id, 'cs_test_due_'.$second->id, 'cs_test_due_'.$third->id], $this->asked());
    }

    #[Test]
    public function an_error_that_is_not_the_providers_does_not_stop_the_orders_after_it_either(): void
    {
        Exceptions::fake();

        // Orders that never got a payment go straight to being cancelled, so the lifecycle is the only thing asked.
        [$broken, $second, $third] = Order::factory()->count(3)->create(['expires_at' => now()->subMinute()])->all();

        $failure = new TypeError('The lock timed out.');
        $lifecycle = Mockery::mock(OrderLifecycle::class);
        $lifecycle->shouldReceive('cancel')->andReturnUsing(fn (Order $order): bool => $order->is($broken) ? throw $failure : true);
        $this->app->instance(OrderLifecycle::class, $lifecycle);

        $result = app(PendingOrderExpirer::class)->expireOrders([$broken->id, $second->id, $third->id]);

        $this->assertSame(['cancelled' => 2, 'paid' => 0, 'skipped' => 1], $result);
        Exceptions::assertReportedCount(1);
        $this->assertSame([$failure], Exceptions::reported());
    }

    #[Test]
    public function an_order_the_provider_could_not_be_asked_about_is_left_out_of_the_schedule_for_half_an_hour(): void
    {
        Queue::fake();
        Exceptions::fake();

        $stuck = $this->dueOrder(askable: false);
        $other = $this->dueOrder();
        $start = now();

        $schedule = fn () => (new ExpirePendingOrders)->handle(app(PendingOrderExpirer::class));
        $worker = fn () => Queue::pushed(ExpireOrders::class)->each(fn (ExpireOrders $job) => $job->handle(app(PendingOrderExpirer::class)));

        $schedule();
        $this->assertSame([[$stuck->id, $other->id]], $this->queuedChunks());
        $worker();
        $this->assertSame(OrderStatus::Cancelled, $other->fresh()->status);

        // Five minutes after it failed, and twenty-nine: the order is still due, and nobody is asked about it.
        foreach ([5, 24] as $minutes) {
            $this->travel($minutes)->minutes();

            Queue::fake();
            $schedule();
            $this->assertSame([], $this->queuedChunks(), 'the order is left out '.now()->diffInMinutes($start, absolute: true).' minutes after it failed');
        }

        // A moment over thirty minutes after it failed, it is asked again.
        $this->travel(61)->seconds();
        Queue::fake();
        $schedule();
        $this->assertSame([[$stuck->id]], $this->queuedChunks());

        $this->assertCount(1, collect($this->asked())->filter(fn (string $session): bool => $session === 'cs_test_due_'.$stuck->id));
        $worker();
        $this->assertCount(2, collect($this->asked())->filter(fn (string $session): bool => $session === 'cs_test_due_'.$stuck->id));
        Exceptions::assertReportedCount(2);
    }

    #[Test]
    public function a_job_that_was_queued_before_the_failure_does_not_ask_about_the_order_again(): void
    {
        Queue::fake();
        Exceptions::fake();

        $stuck = $this->dueOrder(askable: false);
        $expirer = app(PendingOrderExpirer::class);

        $expirer->expireOrders([$stuck->id]);
        $this->assertCount(1, $this->asked());

        // A second job for the same order, queued by an earlier run of the schedule and reached only now.
        (new ExpireOrders([$stuck->id]))->handle($expirer);

        $this->assertCount(1, $this->asked());
        $this->assertSame(['cancelled' => 0, 'paid' => 0, 'skipped' => 0], $expirer->expireOrders([$stuck->id]));
        Exceptions::assertReportedCount(1);
    }

    #[Test]
    public function an_order_that_failed_does_not_make_the_other_orders_wait(): void
    {
        Queue::fake();
        Exceptions::fake();

        $stuck = $this->dueOrder(askable: false);
        app(PendingOrderExpirer::class)->expireOrders([$stuck->id]);

        // Another order's window runs out a minute later: the wait is for the order that failed, and only for it.
        $later = $this->dueOrder();

        (new ExpirePendingOrders)->handle(app(PendingOrderExpirer::class));

        $this->assertSame([[$later->id]], $this->queuedChunks());
    }

    #[Test]
    public function the_failure_is_reported_once_in_that_time_and_not_at_every_run_of_the_schedule(): void
    {
        Queue::fake();
        Exceptions::fake();

        $this->dueOrder(askable: false);

        foreach (range(1, 4) as $run) {
            Queue::fake();
            (new ExpirePendingOrders)->handle(app(PendingOrderExpirer::class));
            Queue::pushed(ExpireOrders::class)->each(fn (ExpireOrders $job) => $job->handle(app(PendingOrderExpirer::class)));
            $this->travel(5)->minutes();
        }

        Exceptions::assertReportedCount(1);
    }

    #[Test]
    public function the_command_asks_about_every_order_whatever_the_wait(): void
    {
        Exceptions::fake();

        $stuck = $this->dueOrder(askable: false);

        app(PendingOrderExpirer::class)->expireOrders([$stuck->id]);
        $this->assertSame(OrderStatus::Pending, $stuck->fresh()->status);

        // The provider answers again, and somebody runs the command by hand: it does not wait out the half hour.
        $this->stripe->sessions['cs_test_due_'.$stuck->id] = ['id' => 'x', 'status' => 'expired', 'payment_status' => 'unpaid', 'payment_intent' => null];

        $this->artisan('commerce:expire-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $stuck->fresh()->status);
    }

    #[Test]
    public function the_command_walks_every_page_of_due_orders(): void
    {
        $orders = collect(range(1, 60))->map(fn () => $this->dueOrder());

        $result = app(PendingOrderExpirer::class)->run();

        $this->assertSame(['cancelled' => 60, 'paid' => 0, 'skipped' => 0], $result);
        $this->assertSame(60, Order::query()->whereKey($orders->pluck('id'))->where('status', OrderStatus::Cancelled->value)->count());
    }

    #[Test]
    public function the_command_goes_past_orders_that_stay_pending_and_asks_about_each_once(): void
    {
        Exceptions::fake();

        // Two pages of orders that nobody can answer about: they are still due when the walk reaches the end, so the walk must not
        // come back to them (it would, if a page started at its lowest id and not above its highest).
        $stuck = collect(range(1, 30))->map(fn () => $this->dueOrder(askable: false));

        $result = app(PendingOrderExpirer::class)->run();

        $this->assertSame(['cancelled' => 0, 'paid' => 0, 'skipped' => 30], $result);
        $this->assertCount(30, $this->asked());
        $this->assertSame($stuck->map(fn (Order $order): string => 'cs_test_due_'.$order->id)->all(), $this->asked());
    }

    #[Test]
    public function having_nothing_to_do_is_not_an_error(): void
    {
        $nothing = ['cancelled' => 0, 'paid' => 0, 'skipped' => 0];

        $this->assertSame([], app(PendingOrderExpirer::class)->readyToAsk([]));
        $this->assertSame($nothing, app(PendingOrderExpirer::class)->expireOrders([]));
        $this->assertSame($nothing, app(PendingOrderExpirer::class)->run());

        // And an order nothing has been said about is ready, whatever else is in the cache.
        $this->assertSame([7, 9], app(PendingOrderExpirer::class)->readyToAsk([7, 9]));
    }
}
