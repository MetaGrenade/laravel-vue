<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Models\Badge;
use App\Models\Blog;
use App\Models\DownloadGrant;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Commerce\Digital\LicenceKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a signed-in customer sees of their shop activity on the dashboard and the order history: their own
 * orders, where to open them to find a key or a file, and nothing that belongs to anyone else.
 */
class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    /**
     * A paid order of the user's, holding the given lines.
     *
     * @param  list<array{description?: string, quantity?: int, product?: Product, keys?: bool}>  $lines
     */
    private function order(User $user, array $lines = [['description' => 'Thing']], OrderPaymentStatus $payment = OrderPaymentStatus::Paid, array $attributes = []): Order
    {
        $order = Order::factory()->forUser($user)->paid()->create(['payment_status' => $payment, 'grand_total' => '10.00', ...$attributes]);

        foreach ($lines as $line) {
            $order->items()->create([
                'product_id' => ($line['product'] ?? null)?->id,
                'quantity' => $line['quantity'] ?? 1,
                'unit_price' => '10.00',
                'subtotal' => '10.00',
                'description' => $line['description'] ?? 'Thing',
                'requires_shipping' => false,
                'delivers_keys' => $line['keys'] ?? false,
            ]);
        }

        return $order->load('items');
    }

    private function dashboard(User $user)
    {
        return $this->actingAs($user)->get(route('dashboard'));
    }

    // --- Their own orders --------------------------------------------------------------------------

    #[Test]
    public function the_dashboard_lists_the_customers_five_newest_orders_and_nobody_elses(): void
    {
        $user = $this->user();
        $other = $this->user();
        $orders = collect(range(1, 6))->map(fn () => $this->order($user));
        $this->order($other, [['description' => 'Someone else\'s secret purchase']]);

        $response = $this->dashboard($user)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('shop.summary.orders', 6)
            ->has('shop.recent_orders', 5)
            ->where('shop.recent_orders.0.number', $orders->last()->number)
            ->where('shop.recent_orders.4.number', $orders[1]->number)
            ->where('shop.recent_orders.0.url', route('shop.checkout.complete', ['order' => $orders->last()->public_id])));

        $this->assertStringNotContainsString('secret purchase', $response->getContent());
    }

    #[Test]
    public function an_order_is_found_through_whoever_owns_it_not_whoever_placed_it(): void
    {
        $owner = $this->user();
        $placedBy = $this->user();
        $order = $this->order($owner);
        $order->forceFill(['user_id' => $placedBy->id])->save();

        $this->dashboard($owner)->assertInertia(fn (Assert $page) => $page->where('shop.summary.orders', 1));
        $this->dashboard($placedBy)->assertInertia(fn (Assert $page) => $page->where('shop.summary.orders', 0)->where('shop.recent_orders', []));
    }

    #[Test]
    public function the_summary_counts_orders_awaiting_payment_and_in_progress(): void
    {
        $user = $this->user();
        $this->order($user); // paid, processing
        Order::factory()->forUser($user)->create(); // pending, unpaid
        Order::factory()->forUser($user)->create();

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('shop.summary.orders', 3)
            ->where('shop.summary.in_progress', 1)
            ->where('shop.summary.awaiting_payment', 2));
    }

    // --- Keys and downloads ------------------------------------------------------------------------

    #[Test]
    public function an_order_says_how_many_keys_and_whether_downloads_it_holds_without_ever_sending_a_key(): void
    {
        $user = $this->user();
        $product = Product::factory()->keyed(3)->create();
        $order = $this->order($user, [['description' => 'Pro Licence', 'quantity' => 2, 'product' => $product, 'keys' => true]]);
        app(LicenceKeys::class)->issue($order);

        $response = $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('shop.recent_orders.0.digital', ['keys' => 2, 'keys_waiting' => 0, 'downloads' => false])
            ->where('shop.summary.digital', 1)
            ->where('shop.summary.keys_waiting', 0)
            ->where('shop.digital.0.description', 'Pro Licence')
            ->where('shop.digital.0.keys', 2)
            ->where('shop.digital.0.has_keys', true)
            ->where('shop.digital.0.url', route('shop.checkout.complete', ['order' => $order->public_id])));

        $this->assertStringNotContainsString("KEY-1-{$product->id}", $response->getContent(), 'keys are only on the order page');
        $this->assertStringNotContainsString("KEY-2-{$product->id}", $response->getContent());
    }

    #[Test]
    public function keys_still_to_come_are_counted_so_the_customer_knows_to_look(): void
    {
        $user = $this->user();
        $product = Product::factory()->keyed(1)->create();
        $order = $this->order($user, [['description' => 'Pro Licence', 'quantity' => 3, 'product' => $product, 'keys' => true]]);
        app(LicenceKeys::class)->issue($order);

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('shop.recent_orders.0.digital.keys', 1)
            ->where('shop.recent_orders.0.digital.keys_waiting', 2)
            ->where('shop.summary.keys_waiting', 2)
            ->where('shop.digital.0.keys_waiting', 2));
    }

    #[Test]
    public function an_order_with_downloads_says_so_until_they_are_revoked_or_expire(): void
    {
        $user = $this->user();
        $product = Product::factory()->create();
        $order = $this->order($user, [['description' => 'Ebook', 'product' => $product]]);
        $grant = DownloadGrant::create(['order_id' => $order->id, 'order_item_id' => $order->items->sole()->id, 'product_id' => $product->id]);

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('shop.recent_orders.0.digital.downloads', true)
            ->where('shop.digital.0.downloads', true)
            ->where('shop.digital.0.has_keys', false));

        $grant->forceFill(['expires_at' => now()->subDay()])->save();
        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('shop.recent_orders.0.digital.downloads', false)
            ->where('shop.digital', []));

        $grant->forceFill(['expires_at' => null, 'revoked_at' => now(), 'revoked_reason' => DownloadGrant::BY_STAFF])->save();
        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page->where('shop.digital', []));
    }

    #[Test]
    public function an_order_refunded_in_full_offers_nothing_to_open(): void
    {
        $user = $this->user();
        $product = Product::factory()->keyed(2)->create();
        $order = $this->order($user, [['description' => 'Pro Licence', 'product' => $product, 'keys' => true]], OrderPaymentStatus::Refunded);
        app(LicenceKeys::class)->issue($order);

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('shop.recent_orders.0.payment_status', 'refunded')
            ->where('shop.recent_orders.0.digital', ['keys' => 0, 'keys_waiting' => 0, 'downloads' => false])
            ->where('shop.digital', [])
            ->where('shop.summary.digital', 0));
    }

    #[Test]
    public function an_unpaid_order_offers_nothing_to_open_yet(): void
    {
        $user = $this->user();
        $product = Product::factory()->keyed(2)->create();
        $order = $this->order($user, [['description' => 'Pro Licence', 'product' => $product, 'keys' => true]], OrderPaymentStatus::Unpaid);

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('shop.recent_orders.0.digital.keys', 0)
            ->where('shop.recent_orders.0.digital.keys_waiting', 0)
            ->where('shop.digital', []));
    }

    #[Test]
    public function other_customers_digital_purchases_are_never_listed(): void
    {
        $product = Product::factory()->keyed(2)->create();
        $other = $this->user();
        $theirs = $this->order($other, [['description' => 'Pro Licence', 'product' => $product, 'keys' => true]]);
        app(LicenceKeys::class)->issue($theirs);

        $this->dashboard($this->user())->assertInertia(fn (Assert $page) => $page->where('shop.digital', [])->where('shop.summary.digital', 0));
    }

    // --- The shop switched off ---------------------------------------------------------------------

    #[Test]
    public function nothing_about_orders_is_offered_when_the_shop_is_switched_off(): void
    {
        $user = $this->user();
        $this->order($user, [['description' => 'Visible only when the shop is on']]);
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $response = $this->dashboard($user)->assertOk()->assertInertia(fn (Assert $page) => $page->where('shop', null));

        $this->assertStringNotContainsString('Visible only when the shop is on', $response->getContent());
    }

    #[Test]
    public function sections_that_are_switched_off_leave_the_activity_and_reading_lists(): void
    {
        $user = $this->user();
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'Chat', 'slug' => 'chat', 'position' => 1]);
        ForumThread::create(['forum_board_id' => $board->id, 'user_id' => $user->id, 'title' => 'My forum thread', 'slug' => 'my-forum-thread', 'is_published' => true]);
        Blog::factory()->published()->create(['title' => 'An article worth reading']);

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('recentItems', fn ($items) => collect($items)->contains(fn ($item) => str_contains($item['summary'], 'My forum thread')))
            ->has('recommendedArticles', 1));

        SystemSetting::set('website_sections', ['blog' => false, 'forum' => false, 'support' => false, 'commerce' => true]);

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page->where('recentItems', [])->where('recommendedArticles', []));
    }

    // --- Cost --------------------------------------------------------------------------------------

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_number_of_orders(): void
    {
        $user = $this->user();
        $product = Product::factory()->keyed(20)->create();

        // The SQL of one dashboard request, in order, with the ids and quantities that differ taken out.
        $count = function () use ($user): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->dashboard($user)->assertOk();
            $queries = array_map(fn (array $query) => preg_replace('/\bin \([\d, ?]+\)/', 'in (?)', $query['query']), DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $order = $this->order($user, [['description' => 'Pro Licence', 'product' => $product, 'keys' => true]]);
        app(LicenceKeys::class)->issue($order);
        $count(); // the first request also warms what every request loads once (permissions, settings)
        $few = $count();

        foreach (range(1, 4) as $unused) {
            $more = $this->order($user, [['description' => 'Pro Licence', 'product' => $product, 'keys' => true], ['description' => 'Extra']]);
            app(LicenceKeys::class)->issue($more);
        }

        $this->assertSame($few, $count(), 'five orders run the same queries as one');
    }

    // --- The order history page --------------------------------------------------------------------

    #[Test]
    public function the_order_history_pages_through_the_customers_orders_and_shows_what_each_holds(): void
    {
        $user = $this->user();
        $product = Product::factory()->keyed(1)->create();
        $keyed = $this->order($user, [['description' => 'Pro Licence', 'product' => $product, 'keys' => true]]);
        app(LicenceKeys::class)->issue($keyed);
        collect(range(1, 11))->each(fn () => $this->order($user));
        $this->order($this->user());

        $this->actingAs($user)->get(route('shop.orders'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/Orders')
            ->has('orders.data', 10)
            ->where('orders.last_page', 2)
            ->where('orders.total', 12)
            ->where('orders.data.0.digital', ['keys' => 0, 'keys_waiting' => 0, 'downloads' => false]));

        $this->actingAs($user)->get(route('shop.orders', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 2)
            ->where('orders.data.1.number', $keyed->number)
            ->where('orders.data.1.digital.keys', 1)
            ->where('orders.data.1.url', route('shop.checkout.complete', ['order' => $keyed->public_id])));
    }

    #[Test]
    public function the_order_history_needs_the_shop_and_a_signed_in_customer(): void
    {
        $this->get(route('shop.orders'))->assertRedirect(route('login'));

        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);
        $this->actingAs($this->user())->get(route('shop.orders'))->assertNotFound();
    }

    // --- Plan and account --------------------------------------------------------------------------

    #[Test]
    public function the_plan_card_is_absent_when_no_plans_are_offered_and_the_user_has_none(): void
    {
        $this->dashboard($this->user())->assertInertia(fn (Assert $page) => $page->where('plan', null));
    }

    #[Test]
    public function the_plan_card_invites_a_user_without_a_subscription_to_choose_one_when_plans_are_offered(): void
    {
        SubscriptionPlan::factory()->create();

        $this->dashboard($this->user())->assertInertia(fn (Assert $page) => $page->where('plan.state', 'none')->where('plan.name', null));
    }

    #[Test]
    public function the_plan_card_describes_the_subscription_and_how_it_stands(): void
    {
        $user = $this->user();
        $plan = SubscriptionPlan::factory()->create(['name' => 'Pro Plan', 'stripe_price_id' => 'price_pro_test', 'interval' => 'year']);
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => config('billing.subscription_name', 'default'),
            'stripe_id' => 'sub_dash_1',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro_test',
            'quantity' => 1,
        ]);

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('plan.state', 'active')
            ->where('plan.name', 'Pro Plan')
            ->where('plan.interval', 'year')
            ->where('plan.ends_at', null));

        $subscription->forceFill(['stripe_status' => 'past_due'])->save();
        $this->dashboard($user->fresh())->assertInertia(fn (Assert $page) => $page->where('plan.state', 'attention'));

        $subscription->forceFill(['stripe_status' => 'active', 'ends_at' => now()->addDays(10)])->save();
        $this->dashboard($user->fresh())->assertInertia(fn (Assert $page) => $page->where('plan.state', 'grace')->where('plan.ends_at', fn ($end) => $end !== null));

        $subscription->forceFill(['ends_at' => now()->subDay()])->save();
        $this->dashboard($user->fresh())->assertInertia(fn (Assert $page) => $page->where('plan.state', 'ended'));

        $this->assertNotNull($plan->id);
    }

    #[Test]
    public function the_account_card_reports_two_factor_reputation_and_badges(): void
    {
        $user = $this->user();
        $user->forceFill(['reputation_points' => 42])->save();

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page
            ->where('account.two_factor_enabled', false)
            ->where('account.reputation', 42)
            ->where('account.badges', 0));

        $badge = Badge::create(['name' => 'First Reply', 'slug' => 'first-reply', 'description' => 'A first reply.', 'points_required' => 5, 'is_active' => true]);
        $user->badges()->attach($badge->id, ['awarded_at' => now()]);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->dashboard($user)->assertInertia(fn (Assert $page) => $page->where('account.two_factor_enabled', true)->where('account.badges', 1));
    }
}
