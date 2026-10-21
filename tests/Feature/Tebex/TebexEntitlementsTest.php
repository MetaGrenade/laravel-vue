<?php

namespace Tests\Feature\Tebex;

use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProviderSubscription;
use App\Models\Refund;
use App\Models\TebexPackage;
use App\Models\TebexPackageEntitlement;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Support\Commerce\GuestCarryOver;
use App\Support\Commerce\OrderRefunder;
use App\Support\Entitlements\OrderEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTebexBaskets;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\Support\InteractsWithTebexWebhooks;
use Tests\TestCase;

/**
 * What a Tebex purchase gives the buyer's account, and when it is taken back: given when the order is paid, taken back with
 * a refund in full, a lost dispute or the end of the recurring payment it came with, and given back if the reason goes.
 */
class TebexEntitlementsTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use InteractsWithTebexWebhooks;
    use RefreshDatabase;

    private const REFERENCE = 'tbx-r-4a1b2c';

    private FakeTebexBaskets $tebex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        $this->setUpTebex();
        $this->setUpTebexWebhooks();
        $this->sellThroughTebex();
        Notification::fake();

        $this->tebex = $this->fakeTebexBaskets();
    }

    /**
     * Put a package in the cart and check out through the real checkout, as a buyer would.
     *
     * @return array{0: Order, 1: Payment, 2: TebexPackage}
     */
    private function placeOrder(bool $recurring = false, int $quantity = 1, ?TebexPackage $alongside = null): array
    {
        if ($alongside !== null) {
            $this->post(route('shop.cart.packages.store'), ['package_id' => $alongside->tebex_id, 'quantity' => 1])->assertSessionHas('success');
        }

        $package = $recurring
            ? TebexPackage::factory()->subscription()->priced('4.50')->create(['ident' => 'mvp-monthly', 'name' => 'MVP (monthly)'])
            : TebexPackage::factory()->priced('10.00')->create(['ident' => 'vip-rank', 'name' => 'VIP rank']);

        $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => $quantity])->assertSessionHas('success');
        $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'name' => 'Ada Buyer', 'token' => (string) Str::uuid()])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        return [$order, $order->payments()->firstOrFail(), $package];
    }

    private function feature(string $key = 'reports'): Entitlement
    {
        return Entitlement::factory()->create(['key' => $key, 'label' => ucfirst($key)]);
    }

    private function limit(string $key = 'team_seats'): Entitlement
    {
        return Entitlement::factory()->limit()->create(['key' => $key, 'label' => ucfirst($key)]);
    }

    /** Set a package to give an entitlement (for a limit, how much one unit adds). */
    private function give(TebexPackage $package, Entitlement $entitlement, ?int $value = null): void
    {
        TebexPackageEntitlement::query()->create(['tebex_package_id' => $package->id, 'entitlement_id' => $entitlement->id, 'value' => $value]);
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function deliver(string $type, array $subject, ?string $id = null, ?string $date = null)
    {
        return $this->deliverToTebexEndpoint($this->tebexDelivery($type, $subject, $id, $date));
    }

    /** The payment of the order, reported complete. */
    private function pay(Order $order, Payment $payment, bool $recurring = false): void
    {
        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, $recurring ? ['recurring_payment_reference' => self::REFERENCE] : []))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function recurring(string $type, Order $order, Payment $payment, array $subject = [], ?string $date = null)
    {
        return $this->deliver($type, $this->tebexRecurringFor($order, $payment, self::REFERENCE, $subject), null, $date);
    }

    /** Tebex's refund of the order's payment ($status 2 is done, 21 is pending). */
    private function refund(Order $order, Payment $payment, int $status = 2, bool $recurring = false): void
    {
        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment, [
            ...($recurring ? ['recurring_payment_reference' => self::REFERENCE] : []),
            'status' => ['id' => $status, 'description' => 'Refund'],
        ]))->assertOk();
    }

    private function holds(User $user, string $key): bool
    {
        return $user->canUse($key);
    }

    /** @return list<UserEntitlement> */
    private function grants(): array
    {
        return UserEntitlement::query()->orderBy('id')->get()->all();
    }

    // --- Giving ------------------------------------------------------------------------------------------------------------

    #[Test]
    public function paying_gives_the_buyers_account_what_the_package_is_set_to_give(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder(quantity: 2);
        $this->give($package, $this->feature());
        $this->give($package, $this->limit(), 5);

        $this->assertSame([], $this->grants(), 'nothing is given before the money is');
        $this->assertFalse($this->holds($user, 'reports'));

        $this->pay($order, $payment);

        $grants = UserEntitlement::query()->with('entitlement')->orderBy('id')->get();
        $this->assertCount(2, $grants);

        foreach ($grants as $grant) {
            $this->assertSame($order->id, $grant->order_id);
            $this->assertSame($order->items->first()->id, $grant->order_item_id);
            $this->assertSame('tebex', $grant->source);
            $this->assertTrue($grant->isOwnedBy($user));
            $this->assertTrue($grant->isActive());
        }

        $this->assertNull($grants->firstWhere('entitlement.key', 'reports')->value, 'a feature has no amount');
        $this->assertSame(10, $grants->firstWhere('entitlement.key', 'team_seats')->value, 'five a unit, and two units bought');

        $this->assertTrue($user->canUse('reports'));
        $this->assertTrue($user->canUse('team_seats'));
        $this->assertSame(10, $user->limitOf('team_seats'));
        $this->assertFalse($user->canUse('something_else'));
    }

    #[Test]
    public function a_payment_reported_again_gives_nothing_twice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $this->give($package, $this->limit(), 3);

        $this->pay($order, $payment);
        $this->pay($order, $payment);

        $this->assertCount(2, $this->grants());
        $this->assertSame(3, $user->limitOf('team_seats'));
    }

    #[Test]
    public function granting_the_same_paid_order_again_gives_nothing_more(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $this->pay($order, $payment);

        $this->assertSame(0, app(OrderEntitlements::class)->grant($order->fresh()));
        $this->assertCount(1, $this->grants());
    }

    #[Test]
    public function a_package_set_to_give_nothing_gives_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment] = $this->placeOrder();

        $this->pay($order, $payment);

        $this->assertSame([], $this->grants());
        $this->assertTrue($user->entitlementSet()->isEmpty());
    }

    #[Test]
    public function an_order_line_whose_package_is_gone_gives_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $order->items()->update(['tebex_package_id' => null]);

        // (Paid through the webhook, a payment for such an order is held for review; this asks the grant itself.)
        $this->assertSame(0, app(OrderEntitlements::class)->grant($order->fresh()));
        $this->assertSame([], $this->grants());
    }

    #[Test]
    public function a_payment_held_for_review_gives_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $other = TebexPackage::factory()->priced('1.00')->create(['ident' => 'cheap-thing']);
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][0]['id'] = $other->tebex_id;

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        $this->assertSame([], $this->grants());
        $this->assertFalse($user->canUse('reports'));
    }

    #[Test]
    public function changing_a_package_afterwards_does_not_reach_what_was_already_bought(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $seats = $this->limit();
        $this->give($package, $seats, 5);
        $this->pay($order, $payment);

        TebexPackageEntitlement::query()->where('tebex_package_id', $package->id)->update(['value' => 50]);
        $this->give($package, $this->feature());
        $this->pay($order, $payment);

        $this->assertCount(1, $this->grants());
        $this->assertSame(5, $user->limitOf('team_seats'), 'what was paid for is what was given');
        $this->assertFalse($user->canUse('reports'));
    }

    #[Test]
    public function the_grants_of_a_guests_order_belong_to_nobody_until_the_order_is_claimed(): void
    {
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $this->pay($order, $payment);

        $grant = UserEntitlement::query()->sole();
        $this->assertNull($grant->owner_type, 'there is no account to give it to yet');

        $user = User::factory()->create(['email' => 'buyer@example.com']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->assertFalse($user->canUse('reports'));

        app(GuestCarryOver::class)->claimOrders($user);

        $this->assertTrue($grant->fresh()->isOwnedBy($user));
        $this->assertTrue($user->canUse('reports'));
    }

    #[Test]
    public function a_renewal_gives_nothing_more(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder(recurring: true);
        $this->give($package, $this->limit(), 4);
        $this->pay($order, $payment, recurring: true);

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, ['transaction_id' => 'tbx-renewal-0001', 'recurring_payment_reference' => self::REFERENCE]))->assertOk();

        $this->assertSame(1, ProviderSubscription::query()->sole()->renewals_count);
        $this->assertCount(1, $this->grants());
        $this->assertSame(4, $user->limitOf('team_seats'), 'a renewal keeps what was given, it does not add to it');
    }

    // --- Taking back with the money ------------------------------------------------------------------------------------------

    #[Test]
    public function a_refund_in_full_takes_back_what_the_order_gave(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $this->give($package, $this->limit(), 5);
        $this->pay($order, $payment);
        $this->assertTrue($user->canUse('reports'));

        $this->refund($order, $payment);

        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);

        foreach ($this->grants() as $grant) {
            $this->assertNotNull($grant->revoked_at);
            $this->assertSame(UserEntitlement::BY_REFUND, $grant->revoked_reason);
        }

        $this->assertFalse($user->canUse('reports'));
        $this->assertSame(0, $user->limitOf('team_seats'));
    }

    #[Test]
    public function a_refund_that_is_still_pending_takes_nothing_back_until_it_goes_through(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $this->pay($order, $payment);

        $this->refund($order, $payment, status: 21);
        $this->assertTrue($user->canUse('reports'), 'the money has not gone yet');

        $this->refund($order, $payment, status: 2);
        $this->assertFalse($user->canUse('reports'));
    }

    #[Test]
    public function a_refund_that_fails_after_all_gives_it_back(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $this->pay($order, $payment);
        $this->refund($order, $payment);
        $this->assertFalse($user->canUse('reports'));

        app(OrderRefunder::class)->settle(Refund::query()->sole(), RefundStatus::Failed, null, 'the bank said no');

        $this->assertNotSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertTrue($user->canUse('reports'));
        $this->assertNull(UserEntitlement::query()->sole()->revoked_reason);
    }

    #[Test]
    public function a_lost_dispute_takes_back_what_the_order_gave(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $this->pay($order, $payment);

        $this->deliver('payment.dispute.opened', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 3, 'description' => 'Chargeback']]))->assertOk();
        $this->assertTrue($user->canUse('reports'), 'a dispute that is only opened has taken nothing yet');

        $this->deliver('payment.dispute.lost', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 3, 'description' => 'Chargeback']]))->assertOk();

        $this->assertFalse($user->canUse('reports'));
        $this->assertSame(UserEntitlement::BY_REFUND, UserEntitlement::query()->sole()->revoked_reason);
    }

    #[Test]
    public function a_grant_a_person_took_back_is_left_as_it_is(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $this->pay($order, $payment);
        UserEntitlement::query()->sole()->forceFill(['revoked_at' => now(), 'revoked_reason' => 'staff'])->save();

        app(OrderEntitlements::class)->syncRevocation($order->fresh());

        $this->assertSame('staff', UserEntitlement::query()->sole()->revoked_reason);
        $this->assertFalse($user->canUse('reports'));
    }

    // --- Taking back with the recurring payment ----------------------------------------------------------------------------------

    #[Test]
    public function a_recurring_payment_that_ends_takes_back_what_it_gave(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder(recurring: true);
        $this->give($package, $this->feature());
        $this->pay($order, $payment, recurring: true);
        $this->assertTrue($user->canUse('reports'));

        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 5, 'description' => 'Cancelled']], '2026-12-01T10:00:00+00:00')->assertOk();

        $this->assertFalse($user->canUse('reports'));
        $this->assertSame(UserEntitlement::BY_SUBSCRIPTION, UserEntitlement::query()->sole()->revoked_reason);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status, 'the order was paid; only the recurring payment ended');
    }

    #[Test]
    public function asking_to_cancel_or_missing_a_payment_does_not_take_anything_back(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder(recurring: true);
        $this->give($package, $this->feature());
        $this->pay($order, $payment, recurring: true);

        $this->recurring('recurring-payment.cancellation.requested', $order, $payment, [], '2026-11-01T10:00:00+00:00')->assertOk();
        $this->assertTrue($user->canUse('reports'), 'it runs until the end of what was paid for');

        $this->recurring('recurring-payment.renewed', $order, $payment, ['status' => ['id' => 3, 'description' => 'Overdue'], 'fail_count' => 1], '2026-11-02T10:00:00+00:00')->assertOk();
        $this->assertTrue($user->canUse('reports'), 'Tebex tries again');
    }

    #[Test]
    public function a_recurring_payment_attached_to_a_one_off_package_takes_nothing_back(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder();
        $this->give($package, $this->feature());
        $this->pay($order, $payment);

        // The shop attaches a recurring payment to the order's first package when none of them is recurring.
        ProviderSubscription::factory()->ended()->create(['order_id' => $order->id, 'tebex_package_id' => $package->id]);
        app(OrderEntitlements::class)->syncRevocation($order->fresh());

        $this->assertTrue($user->canUse('reports'), 'only a recurring package lasts as long as a recurring payment');
    }

    #[Test]
    public function a_recurring_payment_that_expires_takes_it_back_and_one_that_is_live_again_gives_it_back(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder(recurring: true);
        $this->give($package, $this->feature());
        $this->pay($order, $payment, recurring: true);

        $this->recurring('recurring-payment.status-changed', $order, $payment, ['status' => ['id' => 4, 'description' => 'Expired']], '2026-11-01T10:00:00+00:00')->assertOk();
        $this->assertFalse($user->canUse('reports'));

        $this->recurring('recurring-payment.status-changed', $order, $payment, ['status' => ['id' => 2, 'description' => 'Active']], '2026-11-02T10:00:00+00:00')->assertOk();
        $this->assertTrue($user->canUse('reports'));
        $this->assertNull(UserEntitlement::query()->sole()->revoked_at);
    }

    #[Test]
    public function a_recurring_payment_ending_takes_back_only_what_the_recurring_package_gave(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $oneOff = TebexPackage::factory()->priced('3.00')->create(['ident' => 'pet-cat', 'name' => 'Pet cat']);
        [$order, $payment, $monthly] = $this->placeOrder(recurring: true, alongside: $oneOff);
        $this->give($monthly, $this->feature('reports'));
        $this->give($oneOff, $this->feature('cat'));
        $this->pay($order, $payment, recurring: true);
        $this->assertTrue($user->canUse('reports') && $user->canUse('cat'));

        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 5, 'description' => 'Cancelled']], '2026-12-01T10:00:00+00:00')->assertOk();

        $this->assertFalse($user->canUse('reports'));
        $this->assertTrue($user->canUse('cat'), 'a one-off package in the same order is not recurring');
    }

    #[Test]
    public function a_recurring_payment_that_ended_before_the_payment_arrived_gives_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder(recurring: true);
        $this->give($package, $this->feature());

        // Tebex does not promise the order its deliveries arrive in.
        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 5, 'description' => 'Cancelled']], '2026-12-01T10:00:00+00:00')->assertOk();
        $this->pay($order, $payment, recurring: true);

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertFalse($user->canUse('reports'));
        $this->assertSame(UserEntitlement::BY_SUBSCRIPTION, UserEntitlement::query()->sole()->revoked_reason);
    }

    #[Test]
    public function a_grant_taken_back_for_a_refund_stays_taken_back_when_the_recurring_payment_is_live_again(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment, $package] = $this->placeOrder(recurring: true);
        $this->give($package, $this->feature());
        $this->pay($order, $payment, recurring: true);
        $this->recurring('recurring-payment.status-changed', $order, $payment, ['status' => ['id' => 4, 'description' => 'Expired']], '2026-11-01T10:00:00+00:00')->assertOk();

        $this->refund($order, $payment, recurring: true);
        $this->assertSame(UserEntitlement::BY_REFUND, UserEntitlement::query()->sole()->revoked_reason, 'the money is gone, whatever else is true');

        $this->recurring('recurring-payment.status-changed', $order, $payment, ['status' => ['id' => 2, 'description' => 'Active']], '2026-11-02T10:00:00+00:00')->assertOk();

        $this->assertFalse($user->canUse('reports'));
    }
}
