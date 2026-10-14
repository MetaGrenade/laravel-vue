<?php

namespace App\Support\Commerce\GiftCards;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Order;
use App\Models\User;
use App\Support\Commerce\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only place a gift card's balance changes.
 *
 * Every change locks the card's row, moves the balance and writes the matching ledger entry in the same
 * transaction, so two orders spending one card take turns (the second sees what the first left) and the
 * balance always equals the sum of the ledger.
 *
 * Lock order, to keep orders and cards from waiting on each other in a circle: a coupon, then the gift card,
 * then stock. An order is placed (card, then stock), cancelled (order, card, then stock) and refunded (order,
 * then card) in that order.
 */
class GiftCards
{
    /** The most one card can hold: what a `decimal(10,2)` column can store. */
    private const MAX = '99999999.99';

    public function enabled(): bool
    {
        return (bool) config('commerce.gift_cards.enabled', true);
    }

    /**
     * Make a card worth this much. The code is on the returned card's `plainCode`, and nowhere else: it is
     * not kept, so this is the only chance to show it.
     *
     * @throws GiftCardException When the amount cannot be a card's value.
     */
    public function issue(Money $amount, ?User $by = null, ?CarbonInterface $expiresAt = null, ?string $note = null): GiftCard
    {
        $currency = strtoupper((string) config('commerce.currency', 'USD'));

        if ($amount->currency !== $currency) {
            throw new GiftCardException("A gift card is in the shop's currency, {$currency}.");
        }

        if ($amount->minor <= 0) {
            throw new GiftCardException('A gift card has to be worth something.');
        }

        if ((float) $amount->toDecimal() > (float) self::MAX) {
            throw new GiftCardException('That is more than a gift card can hold.');
        }

        for ($attempt = 1; ; $attempt++) {
            $code = GiftCardCode::generate();

            try {
                return DB::transaction(function () use ($amount, $by, $expiresAt, $note, $code, $currency) {
                    $normalised = GiftCardCode::normalise($code);

                    $card = GiftCard::query()->create([
                        'code_hash' => GiftCardCode::hash($normalised),
                        'last_four' => GiftCardCode::lastFour($normalised),
                        'currency' => $currency,
                        'initial_amount' => $amount->toDecimal(),
                        'balance' => Money::zero($currency)->toDecimal(),
                        'is_active' => true,
                        'expires_at' => $expiresAt,
                        'note' => filled($note) ? $note : null,
                        'issued_by' => $by?->id,
                    ]);

                    $this->post($card, GiftCardTransaction::ISSUED, $amount, by: $by, note: $note);

                    $card->plainCode = $code;

                    return $card;
                });
            } catch (UniqueConstraintViolationException $exception) {
                // Two codes the same in 2^80: not going to happen, but never fail a request over it.
                if ($attempt >= 5) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Replace the card's code, for a customer who lost it (the old one stops working). Like a new card, the
     * code is only available on the returned card's `plainCode`. The balance does not change.
     */
    public function regenerate(GiftCard $card, ?User $by = null): GiftCard
    {
        for ($attempt = 1; ; $attempt++) {
            $code = GiftCardCode::generate();

            try {
                return DB::transaction(function () use ($card, $by, $code) {
                    $locked = $this->lock($card);
                    $normalised = GiftCardCode::normalise($code);

                    $locked->forceFill([
                        'code_hash' => GiftCardCode::hash($normalised),
                        'last_four' => GiftCardCode::lastFour($normalised),
                    ])->save();

                    $this->post($locked, GiftCardTransaction::ADJUSTED, Money::zero($locked->currency), by: $by, note: 'Code replaced');

                    $locked->plainCode = $code;

                    return $locked;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 5) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Add (or, if negative, take away) value by hand, with a reason that stays in the card's history.
     *
     * @throws GiftCardException When it would take the balance below nothing, or there is no reason.
     */
    public function adjust(GiftCard $card, Money $change, string $note, ?User $by = null): GiftCard
    {
        if ($change->isZero()) {
            throw new GiftCardException('Enter an amount to add or take away.');
        }

        if (trim($note) === '') {
            throw new GiftCardException('Say why, so the history of the card explains it.');
        }

        return DB::transaction(function () use ($card, $change, $note, $by) {
            $locked = $this->lock($card);

            if ((float) $locked->balanceMoney()->add($change)->toDecimal() > (float) self::MAX) {
                throw new GiftCardException('That is more than a gift card can hold.');
            }

            $this->post($locked, GiftCardTransaction::ADJUSTED, $change, by: $by, note: $note);

            return $locked;
        });
    }

    /**
     * Take an order's share of its total off the card, when the order is placed.
     *
     * @throws GiftCardException When the card does not hold that much (the caller prices from the locked card, so
     *                           this is a guard, not an expected answer).
     */
    public function redeem(GiftCard $card, Money $amount, Order $order): void
    {
        if ($amount->minor <= 0) {
            return;
        }

        DB::transaction(function () use ($card, $amount, $order) {
            $locked = $this->lock($card);

            $this->post($locked, GiftCardTransaction::REDEEMED, Money::ofMinor(-$amount->minor, $amount->currency), $order, note: "Order {$order->number}");
        });
    }

    /**
     * What an order is holding on its card now: taken when it was placed, less what was given back.
     */
    public function held(Order $order): Money
    {
        $zero = Money::zero($order->currency);

        if ($order->gift_card_id === null) {
            return $zero;
        }

        $net = GiftCardTransaction::query()
            ->where('gift_card_id', $order->gift_card_id)
            ->where('order_id', $order->id)
            ->whereIn('type', [GiftCardTransaction::REDEEMED, GiftCardTransaction::RELEASED])
            ->get(['amount'])
            ->reduce(fn (Money $carry, GiftCardTransaction $row) => $carry->add(Money::parse($row->amount, $order->currency)), $zero);

        // Spending is negative on the card, so what is held is the opposite.
        return Money::ofMinor(max(0, -$net->minor), $order->currency);
    }

    /**
     * What the shopper's own unpaid checkouts of this cart are holding on the cart's card. Starting checkout again
     * replaces them (which gives the money back), so for this cart it counts as still being on the card.
     */
    public function heldByCart(Cart $cart): Money
    {
        $zero = Money::zero((string) $cart->currency);

        if ($cart->gift_card_id === null) {
            return $zero;
        }

        $orderIds = Order::query()
            ->where('cart_id', $cart->id)
            ->where('status', OrderStatus::Pending->value)
            ->where('gift_card_id', $cart->gift_card_id)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return $zero;
        }

        $net = GiftCardTransaction::query()
            ->where('gift_card_id', $cart->gift_card_id)
            ->whereIn('order_id', $orderIds->all())
            ->whereIn('type', [GiftCardTransaction::REDEEMED, GiftCardTransaction::RELEASED])
            ->get(['amount'])
            ->reduce(fn (Money $carry, GiftCardTransaction $row) => $carry->add(Money::parse($row->amount, $zero->currency)), $zero);

        return Money::ofMinor(max(0, -$net->minor), $zero->currency);
    }

    /**
     * Give back what an order that will not be paid is holding. Safe to call twice: the second finds nothing held.
     */
    public function release(Order $order, string $reason): Money
    {
        $zero = Money::zero($order->currency);

        if ($order->gift_card_id === null) {
            return $zero;
        }

        return DB::transaction(function () use ($order, $reason, $zero) {
            $card = GiftCard::query()->lockForUpdate()->find($order->gift_card_id);
            $held = $this->held($order);

            if ($card === null || $held->isZero()) {
                return $zero;
            }

            $this->post($card, GiftCardTransaction::RELEASED, $held, $order, note: "Order {$order->number}: {$reason}");

            return $held;
        });
    }

    /**
     * Take the card's share again for an order that was cancelled and then paid after all (the customer's money
     * is already taken, so the order stands). Takes what the card still has, even if it was disabled or has
     * expired meanwhile, and returns what it could not cover so the team can sort it out.
     */
    public function reapply(Order $order): Money
    {
        $zero = Money::zero($order->currency);

        if ($order->gift_card_id === null) {
            return $zero;
        }

        return DB::transaction(function () use ($order, $zero) {
            $card = GiftCard::query()->lockForUpdate()->find($order->gift_card_id);
            $owed = Money::parse($order->gift_card_total, $order->currency)->subtract($this->held($order));

            if ($card === null || $owed->minor <= 0) {
                return $zero;
            }

            $take = Money::ofMinor(min($owed->minor, $card->balanceMoney()->minor), $order->currency);

            if ($take->minor > 0) {
                $this->post($card, GiftCardTransaction::REDEEMED, Money::ofMinor(-$take->minor, $take->currency), $order, note: "Order {$order->number}: paid after it was cancelled");
            }

            return $owed->subtract($take);
        });
    }

    /**
     * Put money back on the card an order was paid with, for a refund. Goes back even to a card that has
     * since been disabled or has expired: it is the customer's money, and the team can extend the card.
     */
    public function credit(Order $order, Money $amount, User|int|null $by = null, ?string $note = null): void
    {
        if ($amount->minor <= 0 || $order->gift_card_id === null) {
            return;
        }

        DB::transaction(function () use ($order, $amount, $by, $note) {
            $card = GiftCard::query()->lockForUpdate()->findOrFail($order->gift_card_id);

            $this->post($card, GiftCardTransaction::REFUNDED, $amount, $order, $by, $note ?? "Order {$order->number}: refund");
        });
    }

    /**
     * What the ledger says the balance is. It equals the card's balance unless something wrote around this class.
     */
    public function ledgerBalance(GiftCard $card): Money
    {
        return GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->get(['amount'])
            ->reduce(fn (Money $carry, GiftCardTransaction $row) => $carry->add(Money::parse($row->amount, $card->currency)), Money::zero($card->currency));
    }

    private function lock(GiftCard $card): GiftCard
    {
        return GiftCard::query()->lockForUpdate()->findOrFail($card->id);
    }

    /**
     * Move the balance of a card that is locked, and write the entry that says why.
     */
    private function post(GiftCard $locked, string $type, Money $change, ?Order $order = null, User|int|null $by = null, ?string $note = null): GiftCardTransaction
    {
        $balance = $locked->balanceMoney()->add($change);

        if ($balance->isNegative()) {
            throw new GiftCardException('That is more than the gift card holds.');
        }

        $locked->forceFill(['balance' => $balance->toDecimal()])->save();

        return GiftCardTransaction::query()->create([
            'gift_card_id' => $locked->id,
            'type' => $type,
            'amount' => $change->toDecimal(),
            'balance_after' => $balance->toDecimal(),
            'order_id' => $order?->id,
            'user_id' => $by instanceof User ? $by->id : $by,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
        ]);
    }
}
