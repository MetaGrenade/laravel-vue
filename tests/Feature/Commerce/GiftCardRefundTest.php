<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\RefundIssued;
use App\Support\Commerce\GiftCards\GiftCards;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundException;
use App\Support\Commerce\RefundRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * Refunding an order a gift card paid part of: the card is paid back first, up to what it paid, and what is left goes
 * back the way it was paid.
 */
class GiftCardRefundTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    private const CODE = 'ABCD-EFGH-JKLM-NPQR';

    private OrderRefunder $refunder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
        $this->refunder = $this->app->make(OrderRefunder::class);
    }

    /**
     * A paid order of 50.00 that a card of this size paid (the rest, if any, through Stripe).
     *
     * @return array{0: Order, 1: GiftCard, 2: Product}
     */
    private function paidOrder(string $card = '20.00', string $price = '50.00'): array
    {
        $giftCard = GiftCard::factory()->code(self::CODE)->worth($card)->withHistory()->create();
        $product = Product::factory()->priced($price)->stocked(20)->create();
        $this->cartWith($product, 1);
        $this->post(route('shop.cart.gift-card.store'), ['gift_card' => self::CODE])->assertSessionHasNoErrors();
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();
        $payment = $order->payments()->first();

        if ($payment !== null) {
            $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();
        }

        return [$order->refresh(), $giftCard->refresh(), $product];
    }

    private function refund(Order $order, string $amount, array $options = []): Refund
    {
        return $this->refunder->request(
            $order,
            Money::parse($amount, 'USD'),
            new RefundRequest(...array_replace(['token' => (string) Str::uuid()], $options)),
            User::factory()->create(),
        );
    }

    private function ledgerAgrees(GiftCard $card): void
    {
        $this->assertSame($card->fresh()->balance, app(GiftCards::class)->ledgerBalance($card)->toDecimal(), 'the history of the card agrees with its balance');
    }

    // --- Where the money goes -----------------------------------------------------------------------

    #[Test]
    public function a_refund_within_what_the_card_paid_goes_back_onto_the_card_and_nowhere_else(): void
    {
        [$order, $card] = $this->paidOrder('20.00');
        $this->assertSame('0.00', $card->balance);

        $refund = $this->refund($order, '12.00');

        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertTrue($refund->isGiftCard());
        $this->assertFalse($refund->isProviderRefund());
        $this->assertSame($card->id, $refund->gift_card_id);
        $this->assertSame('12.00', $card->fresh()->balance);
        $this->assertSame([], $this->stripe->refunds, 'Stripe was not asked for anything');

        $order->refresh();
        $this->assertSame('12.00', $order->refunded_total);
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->payment_status);

        $entry = $card->transactions()->get()->last();
        $this->assertSame('refunded', $entry->type);
        $this->assertSame('12.00', $entry->amount);
        $this->assertSame($order->id, $entry->order_id);
        $this->assertTrue($order->events()->where('message', 'like', 'Refunded 12.00 USD to the gift card ending NPQR')->exists());
        $this->ledgerAgrees($card);
    }

    #[Test]
    public function a_refund_beyond_the_cards_share_pays_the_card_first_and_sends_the_rest_through_stripe(): void
    {
        [$order, $card] = $this->paidOrder('20.00');

        $refund = $this->refund($order, '35.00');

        // Two refunds for one request: 20.00 back to the card, 15.00 through Stripe.
        $this->assertSame(2, $order->refunds()->count());
        $this->assertTrue($refund->isProviderRefund(), 'the one the request answers with is the one that went to Stripe');
        $this->assertSame('15.00', $refund->amount);
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame([1500], collect($this->stripe->refunds)->pluck('amount')->values()->all());

        $toCard = $order->refunds()->whereNotNull('gift_card_id')->sole();
        $this->assertSame('20.00', $toCard->amount);
        $this->assertSame(RefundStatus::Succeeded, $toCard->status);
        $this->assertSame('20.00', $card->fresh()->balance);

        $order->refresh();
        $this->assertSame('35.00', $order->refunded_total);
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->payment_status);
        $this->ledgerAgrees($card);
    }

    #[Test]
    public function refunding_everything_pays_back_the_card_and_stripe_and_closes_the_order(): void
    {
        [$order, $card, $product] = $this->paidOrder('20.00');
        $this->assertSame(19, $product->inventoryItems()->sole()->quantity);

        $this->refund($order, '50.00', ['restock' => true]);

        $order->refresh();
        $this->assertSame('50.00', $order->refunded_total);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('20.00', $card->fresh()->balance);
        $this->assertSame([3000], collect($this->stripe->refunds)->pluck('amount')->values()->all(), 'Stripe is only asked for what it took');
        $this->assertSame(20, $product->inventoryItems()->sole()->quantity, 'the stock went back once');
        $this->ledgerAgrees($card);
    }

    #[Test]
    public function an_order_the_card_paid_in_full_is_refunded_to_the_card_with_no_payment_involved(): void
    {
        [$order, $card] = $this->paidOrder('100.00');
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('50.00', $card->balance);

        $refund = $this->refund($order, '50.00');

        $this->assertTrue($refund->isGiftCard());
        $this->assertSame('100.00', $card->fresh()->balance);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame([], $this->stripe->refunds);
        $this->ledgerAgrees($card);
    }

    #[Test]
    public function later_refunds_go_to_the_card_until_what_it_paid_is_back_and_then_to_stripe(): void
    {
        [$order, $card] = $this->paidOrder('20.00');

        $this->refund($order, '12.00');                // all to the card (8.00 of its share is left)
        $second = $this->refund($order, '15.00');      // 8.00 to the card, 7.00 to Stripe

        $this->assertSame('20.00', $card->fresh()->balance);
        $this->assertSame('7.00', $second->amount);
        $this->assertSame([700], collect($this->stripe->refunds)->pluck('amount')->values()->all());
        // (Within one request the refund that goes to Stripe is written first, then the one for the card.)
        $this->assertSame(['12.00', '7.00', '8.00'], $order->refunds()->orderBy('id')->pluck('amount')->all());
        $this->assertSame('27.00', $order->fresh()->refunded_total);
        $this->ledgerAgrees($card);
    }

    // --- Safety -------------------------------------------------------------------------------------

    #[Test]
    public function submitting_the_same_refund_twice_credits_the_card_once(): void
    {
        [$order, $card] = $this->paidOrder('20.00');
        $token = (string) Str::uuid();
        $amount = Money::parse('35.00', 'USD');

        $first = $this->refunder->request($order, $amount, new RefundRequest(token: $token));
        $again = $this->refunder->request($order, $amount, new RefundRequest(token: $token));

        $this->assertSame($first->id, $again->id);
        $this->assertSame(2, $order->refunds()->count());
        $this->assertSame('20.00', $card->fresh()->balance);
        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame('35.00', $order->fresh()->refunded_total);
        $this->ledgerAgrees($card);
    }

    #[Test]
    public function submitting_a_refund_that_only_goes_to_the_card_twice_credits_it_once(): void
    {
        [$order, $card] = $this->paidOrder('20.00');
        $token = (string) Str::uuid();
        $amount = Money::parse('5.00', 'USD');

        $first = $this->refunder->request($order, $amount, new RefundRequest(token: $token));
        $again = $this->refunder->request($order, $amount, new RefundRequest(token: $token));

        $this->assertSame($first->id, $again->id);
        $this->assertSame('5.00', $card->fresh()->balance);
        $this->assertSame(1, $order->refunds()->count());
        $this->ledgerAgrees($card);
    }

    #[Test]
    public function more_than_the_order_is_still_refused_and_the_card_is_left_alone(): void
    {
        [$order, $card] = $this->paidOrder('20.00');

        try {
            $this->refund($order, '50.01');
            $this->fail('a refund of more than the order was allowed');
        } catch (RefundException $exception) {
            $this->assertStringContainsString('At most 50.00', $exception->getMessage());
        }

        $this->assertSame('0.00', $card->fresh()->balance);
        $this->assertSame(0, $order->refunds()->count());
    }

    #[Test]
    public function when_the_refund_cannot_go_to_stripe_the_request_is_refused_before_the_card_is_touched(): void
    {
        [$order, $card] = $this->paidOrder('20.00');
        Payment::query()->update(['provider_payment_id' => null]);

        try {
            $this->refund($order, '35.00');
            $this->fail('a refund Stripe could not be asked for was allowed');
        } catch (RefundException) {
            // refused: nothing was written
        }

        $this->assertSame('0.00', $card->fresh()->balance);
        $this->assertSame(0, $order->refunds()->count());
        $this->ledgerAgrees($card);
    }

    /**
     * An order of 50.00 that a card of 20.00 was to pay 20.00 of, cancelled, whose card then lost 15.00 elsewhere, and
     * which was then paid anyway: the customer paid Stripe 30.00 and the card could only be charged 5.00 again, so the
     * order took in 35.00 and is 15.00 short.
     *
     * @return array{0: Order, 1: GiftCard}
     */
    private function paidLateWithTheCardShort(): array
    {
        $giftCard = GiftCard::factory()->code(self::CODE)->worth('20.00')->withHistory()->create();
        $this->cartWith(Product::factory()->priced('50.00')->stocked(20)->create(), 1);
        $this->post(route('shop.cart.gift-card.store'), ['gift_card' => self::CODE])->assertSessionHasNoErrors();
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();
        $payment = $order->payments()->sole();
        $this->app->make(OrderLifecycle::class)->cancel($order, 'expired');
        $this->app->make(GiftCards::class)->adjust($giftCard, Money::parse('-15.00', 'USD'), 'Spent elsewhere');

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $order->refresh();
        $this->assertSame('15.00', $order->metadata['gift_card_short']);
        $this->assertSame('5.00', $this->app->make(GiftCards::class)->held($order)->toDecimal());

        return [$order, $giftCard->refresh()];
    }

    #[Test]
    public function a_card_that_could_only_cover_part_of_a_late_payment_is_refunded_only_what_it_paid(): void
    {
        [$order, $card] = $this->paidLateWithTheCardShort();
        $this->assertSame('0.00', $card->balance);
        $this->assertSame('5.00', $this->refunder->giftCardRefundable($order)->toDecimal(), 'what the card took, not the share the order was priced with');

        $refund = $this->refund($order, '35.00');

        // 5.00 back onto the card, the other 30.00 the way it was paid: never more onto the card than it paid.
        $this->assertSame('5.00', $order->refunds()->whereNotNull('gift_card_id')->sole()->amount);
        $this->assertSame('30.00', $refund->amount);
        $this->assertSame([3000], collect($this->stripe->refunds)->pluck('amount')->values()->all());
        $this->assertSame('5.00', $card->fresh()->balance);
        $this->ledgerAgrees($card);
        $this->assertSame('0.00', $this->refunder->giftCardRefundable($order)->toDecimal());
    }

    #[Test]
    public function an_order_that_took_in_less_than_its_total_cannot_be_refunded_more_than_it_took(): void
    {
        [$order] = $this->paidLateWithTheCardShort();

        $this->assertSame('35.00', $this->refunder->refundable($order)->toDecimal(), 'the 15.00 the card could not cover was never received');

        try {
            $this->refund($order, '36.00');
            $this->fail('A refund of more than the order took in was accepted.');
        } catch (RefundException $exception) {
            $this->assertStringContainsString('At most 35.00 USD', $exception->getMessage());
        }

        $this->assertSame([], $this->stripe->refunds);

        // Refunding all of it is a refund in full: the order is done with, not left owed 15.00.
        $this->refund($order, '35.00');

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
        $this->assertSame('35.00', $order->refunded_total);
        $this->assertSame('0.00', $this->refunder->refundable($order)->toDecimal());
    }

    // --- By hand, and when Stripe is slow or says no -----------------------------------------------

    #[Test]
    public function a_refund_recorded_by_hand_still_puts_the_cards_share_back_on_the_card(): void
    {
        [$order, $card] = $this->paidOrder('20.00');

        $refund = $this->refund($order, '35.00', ['manual' => true]);

        $this->assertFalse($refund->isProviderRefund());
        $this->assertFalse($refund->isGiftCard());
        $this->assertSame('15.00', $refund->amount);
        $this->assertSame('20.00', $card->fresh()->balance, 'the shop\'s own ledger is not something that is done by hand');
        $this->assertSame([], $this->stripe->refunds);
        $this->assertSame('35.00', $order->fresh()->refunded_total);
    }

    #[Test]
    public function while_stripe_has_not_answered_the_card_is_paid_back_and_the_order_waits_for_the_rest(): void
    {
        [$order, $card] = $this->paidOrder('20.00');
        $this->stripe->nextRefundStatus = 'pending';

        $refund = $this->refund($order, '50.00');

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame('20.00', $card->fresh()->balance);
        $order->refresh();
        $this->assertSame('20.00', $order->refunded_total, 'only what has really been returned counts');
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->payment_status);

        $this->refunder->settle($refund, RefundStatus::Succeeded);

        $order->refresh();
        $this->assertSame('50.00', $order->refunded_total);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
        $this->assertSame('20.00', $card->fresh()->balance, 'and the card is not credited a second time');
        $this->ledgerAgrees($card);
    }

    #[Test]
    public function when_stripe_refuses_the_card_keeps_what_it_was_given_and_the_rest_can_be_tried_again(): void
    {
        [$order, $card] = $this->paidOrder('20.00');
        $this->stripe->nextRefundStatus = 'failed';

        $refund = $this->refund($order, '35.00');

        $this->assertSame(RefundStatus::Failed, $refund->status);
        $order->refresh();
        $this->assertSame('20.00', $order->refunded_total);
        $this->assertSame('20.00', $card->fresh()->balance);
        $this->assertSame('30.00', $this->refunder->refundable($order)->toDecimal(), 'the rest of the order can still be refunded');

        $this->stripe->nextRefundStatus = 'succeeded';
        $this->refund($order, '30.00');

        $this->assertSame('50.00', $order->fresh()->refunded_total);
        $this->assertSame('20.00', $card->fresh()->balance, 'the retry went all to Stripe: the card had been paid back already');
        $this->ledgerAgrees($card);
    }

    // --- Telling the customer ------------------------------------------------------------------------

    #[Test]
    public function the_customer_gets_one_email_for_one_refund_request(): void
    {
        [$order] = $this->paidOrder('20.00');

        $this->refund($order, '35.00');

        Notification::assertSentOnDemandTimes(RefundIssued::class, 1);
    }

    #[Test]
    public function an_email_about_a_refund_to_the_card_says_so_and_has_no_statement_to_wait_for(): void
    {
        [$order] = $this->paidOrder('20.00');

        $this->refund($order, '12.00');

        Notification::assertSentOnDemand(RefundIssued::class, function (RefundIssued $notification) {
            $lines = collect($notification->toMail(new \stdClass)->introLines)->implode("\n");

            $this->assertStringContainsString("We've put 12.00 USD back on your gift card ending NPQR", $lines);
            $this->assertStringNotContainsString('statement', $lines);
            $this->assertStringNotContainsString(self::CODE, $lines);

            return true;
        });
    }

    #[Test]
    public function an_email_about_a_refund_to_stripe_is_as_it_was(): void
    {
        [$order] = $this->paidOrder('20.00');

        $this->refund($order, '50.00', ['notifyCustomer' => true]);

        Notification::assertSentOnDemand(RefundIssued::class, function (RefundIssued $notification) {
            $lines = collect($notification->toMail(new \stdClass)->introLines)->implode("\n");

            $this->assertStringContainsString("We've refunded 30.00 USD for order", $lines);
            $this->assertStringContainsString('statement', $lines);

            return true;
        });
    }

    #[Test]
    public function the_customer_is_not_emailed_when_the_team_asked_for_no_email(): void
    {
        [$order] = $this->paidOrder('20.00');

        $this->refund($order, '12.00', ['notifyCustomer' => false]);
        $this->refund($order, '38.00', ['notifyCustomer' => false]);

        Notification::assertSentOnDemandTimes(RefundIssued::class, 0);
    }

    // --- Orders with no card are untouched -------------------------------------------------------

    #[Test]
    public function an_order_with_no_gift_card_is_refunded_exactly_as_before(): void
    {
        $product = Product::factory()->priced('25.00')->stocked(5)->create();
        $this->cartWith($product, 1);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();

        $refund = $this->refund($order->refresh(), '25.00');

        $this->assertFalse($refund->isGiftCard());
        $this->assertSame(1, $order->refunds()->count());
        $this->assertSame([2500], collect($this->stripe->refunds)->pluck('amount')->values()->all());
        $this->assertSame(0, GiftCard::query()->count());
    }
}
