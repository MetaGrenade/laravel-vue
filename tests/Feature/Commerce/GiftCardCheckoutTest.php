<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Models\User;
use App\Notifications\OrderConfirmation;
use App\Support\Commerce\CheckoutException;
use App\Support\Commerce\CheckoutInput;
use App\Support\Commerce\CustomerDetails;
use App\Support\Commerce\GiftCards\GiftCards;
use App\Support\Commerce\GuestCarryOver;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderPlacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * A customer paying with a gift card: putting it on the cart, what it pays, what the provider is asked for, and what
 * happens to the card when the order is paid, cancelled or expires.
 */
class GiftCardCheckoutTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    private const CODE = 'ABCD-EFGH-JKLM-NPQR';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
    }

    private function card(string $balance = '50.00', string $code = self::CODE): GiftCard
    {
        return GiftCard::factory()->code($code)->worth($balance)->withHistory()->create();
    }

    /**
     * A guest's cart of one product costing this much (a quantity of one).
     */
    private function cart(string $price = '50.00', int $quantity = 1): Product
    {
        $product = Product::factory()->priced($price)->stocked(20)->create();
        $this->cartWith($product, $quantity);

        return $product;
    }

    private function apply(string $code = self::CODE)
    {
        return $this->post(route('shop.cart.gift-card.store'), ['gift_card' => $code]);
    }

    private function checkout(array $overrides = [])
    {
        return $this->post(route('shop.checkout.store'), $this->checkoutPayload($overrides));
    }

    private function order(): Order
    {
        return Order::query()->latest('id')->firstOrFail();
    }

    private function usd(string $amount): Money
    {
        return Money::parse($amount, 'USD');
    }

    // --- Putting a card on the cart ----------------------------------------------------------------

    #[Test]
    public function a_guest_can_put_a_gift_card_on_the_cart_and_take_it_off_again(): void
    {
        $card = $this->card();
        $this->cart();

        $this->apply()->assertRedirect()->assertSessionHas('success', 'Gift card ending NPQR applied.')->assertSessionHasNoErrors();
        $this->assertSame($card->id, Cart::query()->sole()->gift_card_id);

        $this->delete(route('shop.cart.gift-card.destroy'))->assertRedirect()->assertSessionHas('success', 'Gift card removed.');
        $this->assertNull(Cart::query()->sole()->gift_card_id);
    }

    #[Test]
    public function the_code_is_found_whatever_the_case_or_punctuation(): void
    {
        $card = $this->card();
        $this->cart();

        $this->apply('  abcd efgh jklm npqr ')->assertSessionHasNoErrors();

        $this->assertSame($card->id, Cart::query()->sole()->gift_card_id);
    }

    #[Test]
    public function a_code_that_is_not_a_card_is_refused_the_same_way_whatever_is_wrong_with_it(): void
    {
        $this->card();
        $this->cart();
        $message = "We couldn't find that gift card. Check the code and try again.";

        foreach (['ZZZZ-ZZZZ-ZZZZ-ZZZZ', 'nonsense', 'ABCD-EFGH-JKLM-NPQ0', 'ABCD-EFGH-JKLM'] as $code) {
            $this->apply($code)->assertSessionHasErrors(['gift_card' => $message]);
        }

        $this->assertNull(Cart::query()->sole()->gift_card_id);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unusableCards(): array
    {
        return [
            'switched off' => ['inactive', 'cannot be used'],
            'expired' => ['expired', 'has expired'],
            'spent' => ['spent', 'no balance left'],
        ];
    }

    #[Test]
    #[DataProvider('unusableCards')]
    public function a_card_that_cannot_be_used_says_why_and_is_not_put_on_the_cart(string $state, string $why): void
    {
        GiftCard::factory()->code(self::CODE)->{$state}()->create();
        $this->cart();

        $response = $this->apply();

        $response->assertSessionHasErrors('gift_card');
        $this->assertStringContainsString($why, session('errors')->first('gift_card'));
        $this->assertNull(Cart::query()->sole()->gift_card_id);
    }

    #[Test]
    public function a_card_in_another_currency_cannot_be_used(): void
    {
        GiftCard::factory()->code(self::CODE)->create(['currency' => 'EUR']);
        $this->cart();

        $this->apply()->assertSessionHasErrors('gift_card');
        $this->assertNull(Cart::query()->sole()->gift_card_id);
    }

    #[Test]
    public function there_has_to_be_something_in_the_cart(): void
    {
        $this->card();

        $this->apply()->assertSessionHasErrors(['gift_card' => 'Add something to your cart before using a gift card.']);
    }

    #[Test]
    public function a_code_is_required(): void
    {
        $this->cart();

        $this->apply('')->assertSessionHasErrors(['gift_card' => 'Enter your gift card code.']);
    }

    #[Test]
    public function nothing_can_be_applied_when_gift_cards_are_switched_off(): void
    {
        $this->card();
        $this->cart();
        config(['commerce.gift_cards.enabled' => false]);

        $this->apply()->assertSessionHasErrors(['gift_card' => 'Gift cards are not accepted right now.']);
        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('giftCardsEnabled', false)->where('giftCard', null));
    }

    #[Test]
    public function trying_codes_is_slowed_right_down(): void
    {
        $this->cart();

        foreach (range(1, 10) as $unused) {
            $this->apply('ZZZZ-ZZZZ-ZZZZ-ZZZZ')->assertSessionHasErrors('gift_card');
        }

        $this->apply('ZZZZ-ZZZZ-ZZZZ-ZZZZ')->assertStatus(429);
    }

    #[Test]
    public function the_cart_page_describes_the_card_by_its_last_four_characters_and_what_it_holds(): void
    {
        $this->card('35.50');
        $this->cart();
        $this->apply();

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('giftCardsEnabled', true)
            ->where('giftCard', ['last_four' => 'NPQR', 'balance' => '35.50', 'applied' => true, 'problem' => null]));
    }

    #[Test]
    public function a_card_that_has_become_unusable_since_it_was_applied_is_flagged_on_the_cart_page(): void
    {
        $card = $this->card();
        $this->cart();
        $this->apply();
        $card->update(['balance' => '0.00']);

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('giftCard.applied', false)
            ->where('giftCard.problem', 'That gift card has no balance left.'));
    }

    // --- What it pays ------------------------------------------------------------------------------

    #[Test]
    public function the_quote_shows_what_the_card_pays_and_what_is_left_to_pay(): void
    {
        $this->card('20.00');
        $this->cart('50.00');
        $this->apply();

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.grand_total', '50.00')
            ->where('quote.gift_card.last_four', 'NPQR')
            ->where('quote.gift_card.applied', true)
            ->where('quote.gift_card.amount', '20.00')
            ->where('quote.gift_card_total', '20.00')
            ->where('quote.amount_due', '30.00'));
    }

    #[Test]
    public function a_card_worth_more_than_the_order_pays_all_of_it_and_keeps_the_rest(): void
    {
        $this->card('100.00');
        $this->cart('50.00');
        $this->apply();

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.gift_card.amount', '50.00')
            ->where('quote.amount_due', '0.00'));
    }

    #[Test]
    public function the_card_pays_for_shipping_and_tax_as_well_as_the_goods(): void
    {
        ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20']);
        $this->card('40.00');
        $this->cart('50.00');
        $this->apply();

        // 50.00 goods + 5.00 shipping, VAT of 11.00 on all of it: 66.00 in all.
        $this->get(route('shop.checkout', ['ship_country' => 'GB']))->assertInertia(fn (Assert $page) => $page
            ->where('quote.grand_total', '66.00')
            ->where('quote.tax.total', '11.00')
            ->where('quote.gift_card.amount', '40.00')
            ->where('quote.amount_due', '26.00'));
    }

    #[Test]
    public function the_card_is_not_a_discount_so_tax_is_charged_as_if_it_were_not_there(): void
    {
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20', 'applies_to_shipping' => false]);
        $this->cart('100.00');
        $without = $this->get(route('shop.checkout', ['bill_country' => 'GB']))->viewData('page')['props']['quote'];

        $this->card('60.00');
        $this->apply();
        $with = $this->get(route('shop.checkout', ['bill_country' => 'GB']))->viewData('page')['props']['quote'];

        $this->assertSame($without['tax']['total'], $with['tax']['total']);
        $this->assertSame($without['grand_total'], $with['grand_total'], 'the order is worth the same');
        $this->assertSame('60.00', $with['gift_card']['amount']);
        $this->assertSame('60.00', $with['amount_due']);
    }

    #[Test]
    public function a_discount_code_comes_off_first_and_the_card_pays_what_is_left(): void
    {
        Coupon::factory()->percent('50')->create(['code' => 'HALF']);
        $this->card('40.00');
        $this->cart('100.00');
        $this->post(route('shop.cart.coupon.store'), ['code' => 'HALF'])->assertSessionHasNoErrors();
        $this->apply();

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.grand_total', '50.00')
            ->where('quote.gift_card.amount', '40.00')
            ->where('quote.amount_due', '10.00'));
    }

    #[Test]
    public function a_card_that_cannot_be_used_is_shown_with_its_reason_and_pays_nothing_in_the_quote(): void
    {
        $card = $this->card();
        $this->cart('50.00');
        $this->apply();
        $card->update(['expires_at' => now()->subMinute()]);

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.gift_card.applied', false)
            ->where('quote.gift_card.problem', 'That gift card has expired.')
            ->where('quote.amount_due', '50.00'));
    }

    // --- Placing the order -------------------------------------------------------------------------

    #[Test]
    public function an_order_the_card_part_pays_takes_that_much_off_the_card_and_asks_stripe_for_the_rest(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();

        $this->checkout()->assertRedirect();

        $order = $this->order();
        $this->assertSame('50.00', $order->grand_total, 'the order is still worth what it costs');
        $this->assertSame('20.00', $order->gift_card_total);
        $this->assertSame($card->id, $order->gift_card_id);
        $this->assertSame('30.00', $order->amountDue()->toDecimal());
        $this->assertSame(OrderStatus::Pending, $order->status);

        // The card is spent at once, and says which order it went on.
        $this->assertSame('0.00', $card->fresh()->balance);
        $entry = $card->transactions()->get()->last();
        $this->assertSame('redeemed', $entry->type);
        $this->assertSame('-20.00', $entry->amount);
        $this->assertSame($order->id, $entry->order_id);
        $this->assertSame('0.00', app(GiftCards::class)->ledgerBalance($card)->toDecimal(), 'the history agrees with the balance');

        // Stripe is asked for what is left, as one line that says so, and the payment is for that amount.
        $params = $this->stripe->lastCreatedParams();
        $this->assertCount(1, $params['line_items']);
        $this->assertSame(3000, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertStringContainsString('less 20.00 paid by gift card', $params['line_items'][0]['price_data']['product_data']['name']);
        $this->assertSame('30.00', Payment::query()->sole()->amount);
        $this->assertSame(3000, $this->stripe->sessions['cs_test_1']['amount_total']);

        $this->assertTrue($order->events()->where('type', OrderEvent::GIFT_CARD_APPLIED)->exists());
    }

    #[Test]
    public function paying_the_rest_completes_the_order_and_the_card_stays_spent(): void
    {
        $card = $this->card('20.00');
        $product = $this->cart('50.00');
        $this->apply();
        $this->checkout();
        $payment = Payment::query()->sole();

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $order = $this->order()->fresh();
        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('0.00', $card->fresh()->balance);
        $this->assertSame(19, $product->inventoryItems()->sole()->quantity, 'stock was taken as usual');
        $this->assertSame(0, $this->cartItemCount(), 'and the cart was emptied');
    }

    #[Test]
    public function an_order_the_card_pays_in_full_needs_no_payment_provider_at_all(): void
    {
        config(['cashier.secret' => null]);
        $card = $this->card('100.00');
        $product = $this->cart('50.00');
        $this->apply();

        $response = $this->checkout();

        $order = $this->order();
        $response->assertRedirect(); // Inertia::location for the confirmation page
        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertNull($order->payment_provider);
        $this->assertSame('50.00', $order->gift_card_total);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame([], $this->stripe->created, 'Stripe was never asked');
        $this->assertSame('50.00', $card->fresh()->balance, 'only what the order needed was taken');
        $this->assertSame(19, $product->inventoryItems()->sole()->quantity);
        $this->assertSame(0, $this->cartItemCount());
        $this->assertStringContainsString('Paid in full with a gift card ending NPQR', (string) $order->events()->where('type', OrderEvent::PAID)->value('message'));
    }

    #[Test]
    public function the_confirmation_says_what_the_card_paid_and_what_was_charged(): void
    {
        $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $this->checkout();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor(Payment::query()->sole())))->assertOk();

        Notification::assertSentOnDemand(OrderConfirmation::class, function (OrderConfirmation $notification) {
            $lines = collect($notification->toMail(new \stdClass)->introLines)->implode("\n");

            $this->assertStringContainsString('Order total: 50.00 USD', $lines);
            $this->assertStringContainsString('Gift card (ending NPQR): -20.00 USD', $lines);
            $this->assertStringContainsString('Charged to your card: 30.00 USD', $lines);
            $this->assertStringNotContainsString(self::CODE, $lines, 'the code is in no email');

            return true;
        });
    }

    #[Test]
    public function the_confirmation_of_an_order_the_card_paid_in_full_says_so(): void
    {
        $this->card('100.00');
        $this->cart('50.00');
        $this->apply();
        $this->checkout();

        Notification::assertSentOnDemand(OrderConfirmation::class, function (OrderConfirmation $notification) {
            $lines = collect($notification->toMail(new \stdClass)->introLines)->implode("\n");

            $this->assertStringContainsString('It was paid for with your gift card.', $lines);
            $this->assertStringContainsString('Charged to your card: 0.00 USD', $lines);

            return true;
        });
    }

    #[Test]
    public function the_receipt_page_shows_the_card_and_what_it_paid_but_never_the_code(): void
    {
        $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $this->checkout();
        $order = $this->order();

        $page = $this->get($this->app['url']->signedRoute('shop.checkout.complete', ['order' => $order->public_id]));

        $page->assertInertia(fn (Assert $page) => $page
            ->where('order.gift_card_total', '20.00')
            ->where('order.gift_card_last_four', 'NPQR')
            ->where('order.amount_due', '30.00')
            ->where('order.grand_total', '50.00'));
        $this->assertStringNotContainsString(self::CODE, $page->getContent());
        $this->assertStringNotContainsString('ABCDEFGHJKLMNPQR', $page->getContent());
    }

    #[Test]
    public function an_order_that_costs_nothing_leaves_the_card_alone(): void
    {
        $card = $this->card('30.00');
        $this->cart('10.00');
        Coupon::factory()->percent('100')->create(['code' => 'FREE']);
        $this->post(route('shop.cart.coupon.store'), ['code' => 'FREE']);
        $this->apply();

        $this->checkout();

        $order = $this->order();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertNull($order->gift_card_id);
        $this->assertSame('0.00', $order->gift_card_total);
        $this->assertSame('30.00', $card->fresh()->balance);
        $this->assertSame(1, $card->transactions()->count());
    }

    #[Test]
    public function with_gift_cards_switched_off_a_card_left_on_the_cart_is_ignored(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        config(['commerce.gift_cards.enabled' => false]);

        $this->checkout();

        $order = $this->order();
        $this->assertSame('0.00', $order->gift_card_total);
        $this->assertNull($order->gift_card_id);
        $this->assertSame('20.00', $card->fresh()->balance);
        $this->assertSame('50.00', Payment::query()->sole()->amount);
    }

    #[Test]
    public function a_card_that_stopped_working_before_checkout_stops_the_order_and_costs_nothing(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $card->update(['is_active' => false]);

        $this->checkout()->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error', 'Your gift card ending NPQR can\'t be used. That gift card cannot be used.');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame('20.00', $card->fresh()->balance);
    }

    #[Test]
    public function a_cart_with_a_card_that_cannot_pay_anything_is_not_refused_when_it_costs_nothing(): void
    {
        $card = $this->card('20.00');
        $this->cart('10.00');
        $this->apply();
        Coupon::factory()->percent('100')->create(['code' => 'FREE']);
        $this->post(route('shop.cart.coupon.store'), ['code' => 'FREE']);
        $card->update(['is_active' => false]);

        $this->checkout()->assertRedirect();

        $this->assertSame(OrderPaymentStatus::Paid, $this->order()->payment_status);
    }

    #[Test]
    public function while_its_money_is_held_for_a_checkout_the_card_is_still_the_carts_to_use_and_nobody_elses(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $this->checkout();     // pays 20.00 from the card and waits for Stripe: the card now holds 0.00

        // Back from Stripe's page to the same cart: the card is still there for it.
        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.gift_card.applied', true)
            ->where('quote.gift_card.problem', null)
            ->where('quote.gift_card.balance', '20.00')
            ->where('quote.gift_card.amount', '20.00')
            ->where('quote.amount_due', '30.00'));
        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('giftCard.applied', true)
            ->where('giftCard.balance', '20.00'));

        // Someone else with the same code is told it has nothing left, because it has not.
        $other = Str::random(40);
        $this->guestSessionId = $other;
        $this->withCookie(config('session.cookie'), $other);
        $this->cart('30.00');

        $this->apply()->assertSessionHasErrors(['gift_card' => 'That gift card has no balance left.']);

        // Nor does the money held for the first cart count for a cart that merely has the same card on it (it
        // was put there before the card was spent).
        Cart::query()->where('session_id', $other)->update(['gift_card_id' => $card->id]);

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.gift_card.applied', false)
            ->where('quote.gift_card.problem', 'That gift card has no balance left.')
            ->where('quote.amount_due', '30.00'));
    }

    #[Test]
    public function an_order_is_priced_from_what_the_card_really_holds_not_from_what_an_unreplaced_checkout_holds(): void
    {
        $card = $this->card('20.00');
        $product = Product::factory()->digital()->priced('50.00')->stocked(5)->create();
        $cart = $this->cartWith($product, 1);
        $cart->forceFill(['gift_card_id' => $card->id])->save();
        $placer = app(OrderPlacer::class);
        $customer = new CustomerDetails('buyer@example.test');

        $placer->place($cart, $customer, 'stripe', new CheckoutInput);
        $this->assertSame('0.00', $card->fresh()->balance);

        // Starting checkout again replaces the first order before it places the next. Placing without doing that
        // finds the card empty, and says so, rather than promising money that is not there.
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('no balance left');

        try {
            $placer->place($cart->fresh(), $customer, 'stripe', new CheckoutInput);
        } finally {
            $this->assertSame(1, Order::query()->count());
            $this->assertSame('0.00', $card->fresh()->balance);
        }
    }

    // --- Giving the card back ----------------------------------------------------------------------

    #[Test]
    public function an_order_that_expires_unpaid_gives_the_card_its_share_back(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $this->checkout();
        $order = $this->order();
        $this->assertSame('0.00', $card->fresh()->balance);

        app(OrderLifecycle::class)->cancel($order, 'expired');

        $this->assertSame('20.00', $card->fresh()->balance);
        $this->assertSame(['issued' => 1, 'redeemed' => 1, 'released' => 1], [
            'issued' => $card->transactions()->where('type', 'issued')->count(),
            'redeemed' => $card->transactions()->where('type', 'redeemed')->count(),
            'released' => $card->transactions()->where('type', 'released')->count(),
        ]);
        $this->assertTrue($order->events()->where('type', OrderEvent::GIFT_CARD_RELEASED)->where('message', 'like', '20.00 USD went back%')->exists());
        $this->assertSame('20.00', app(GiftCards::class)->ledgerBalance($card)->toDecimal());
    }

    #[Test]
    public function starting_checkout_again_gives_the_card_back_and_takes_it_for_the_new_order(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $this->checkout();
        $first = $this->order();

        $this->checkout();

        $second = $this->order();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
        $this->assertSame('0.00', $card->fresh()->balance, 'spent once, on the new order only');
        $this->assertSame(['redeemed', 'released', 'redeemed'], $card->transactions()->where('type', '!=', 'issued')->pluck('type')->all());
        $this->assertSame('20.00', app(GiftCards::class)->held($second)->toDecimal());
        $this->assertTrue(app(GiftCards::class)->held($first->fresh())->isZero());
    }

    #[Test]
    public function when_stripe_cannot_start_the_payment_the_card_is_given_back(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $this->stripe->failCreateWith = new RuntimeException('Stripe is down');

        $this->checkout()->assertRedirect(route('shop.cart'));

        $this->assertSame(OrderStatus::Cancelled, $this->order()->status);
        $this->assertSame('20.00', $card->fresh()->balance);
    }

    #[Test]
    public function two_checkouts_spending_one_card_take_turns_and_never_overdraw_it(): void
    {
        $card = $this->card('30.00');

        // Two shoppers, two carts of 25.00, the same card.
        $this->cart('25.00');
        $this->apply();
        $second = Str::random(40);
        $first = $this->guestSessionId;

        $this->checkout();
        $this->assertSame('5.00', $card->fresh()->balance);

        $this->guestSessionId = $second;
        $this->withCookie(config('session.cookie'), $second);
        $this->cart('25.00');
        $this->apply();
        $this->checkout();

        [$one, $two] = Order::query()->orderBy('id')->get()->all();
        $this->assertSame('25.00', $one->gift_card_total);
        $this->assertSame('5.00', $two->gift_card_total, 'the second order got what was left');
        $this->assertSame('20.00', $two->amountDue()->toDecimal());
        $this->assertSame('0.00', $card->fresh()->balance);
        $this->assertSame('0.00', app(GiftCards::class)->ledgerBalance($card)->toDecimal());
        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function an_order_paid_after_it_was_cancelled_takes_the_cards_share_again(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $this->checkout();
        $order = $this->order();
        $payment = Payment::query()->sole();
        app(OrderLifecycle::class)->cancel($order, 'expired');
        $this->assertSame('20.00', $card->fresh()->balance);

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertTrue((bool) ($order->metadata['late_payment'] ?? false));
        $this->assertArrayNotHasKey('gift_card_short', $order->metadata);
        $this->assertSame('0.00', $card->fresh()->balance, 'the card is spent on it again');
        $this->assertSame('20.00', app(GiftCards::class)->held($order)->toDecimal());
    }

    #[Test]
    public function when_the_card_cannot_cover_a_late_payment_it_is_flagged_for_a_person(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $this->checkout();
        $order = $this->order();
        $payment = Payment::query()->sole();
        app(OrderLifecycle::class)->cancel($order, 'expired');
        // The card is used up elsewhere while the order is cancelled.
        app(GiftCards::class)->adjust($card, $this->usd('-15.00'), 'Spent elsewhere');

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status, 'the customer paid, so the order stands');
        $this->assertSame('15.00', $order->metadata['gift_card_short']);
        $this->assertSame('0.00', $card->fresh()->balance);
        $this->assertTrue($order->events()->where('type', OrderEvent::GIFT_CARD_SHORT)->exists());
    }

    // --- Guests becoming customers -----------------------------------------------------------------

    #[Test]
    public function a_guests_card_follows_them_into_their_account_cart(): void
    {
        $card = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $user = User::factory()->create();
        $mine = $this->cartWith(Product::factory()->priced('5.00')->stocked(5)->create(), 1, $user);

        $merged = app(GuestCarryOver::class)->mergeCart($user, $this->guestSessionId);

        $this->assertSame($mine->id, $merged->id);
        $this->assertSame($card->id, $merged->gift_card_id);
    }

    #[Test]
    public function the_account_keeps_its_own_card_when_the_guest_had_another(): void
    {
        $theirs = $this->card('20.00');
        $this->cart('50.00');
        $this->apply();
        $user = User::factory()->create();
        $mine = $this->cartWith(Product::factory()->priced('5.00')->stocked(5)->create(), 1, $user);
        $mine->forceFill(['gift_card_id' => $theirs->id])->save();
        $other = $this->card('99.00', 'ZZZZ-YYYY-XXXX-WWWW');
        Cart::query()->whereNull('user_id')->update(['gift_card_id' => $other->id]);

        $merged = app(GuestCarryOver::class)->mergeCart($user, $this->guestSessionId);

        $this->assertSame($theirs->id, $merged->gift_card_id);
    }
}
