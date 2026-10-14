<?php

namespace App\Support\Commerce\GiftCards;

use App\Models\Cart;
use App\Models\GiftCard;
use App\Support\Commerce\Money;

/**
 * The gift card on a shopper's cart: putting one on, taking it off, and describing it for the cart page.
 *
 * Applying a card reserves nothing. It is checked again every time the cart is priced, and only taken from
 * when an order carries it ({@see GiftCards::redeem()}), so a card sitting on a cart can be used by anyone who
 * has the code until then.
 */
class CartGiftCards
{
    public function __construct(private readonly GiftCards $cards) {}

    public function enabled(): bool
    {
        return $this->cards->enabled();
    }

    /**
     * @throws GiftCardRejected When there is no such card, or it cannot be used.
     */
    public function apply(Cart $cart, string $input): GiftCard
    {
        if (! $this->cards->enabled()) {
            throw new GiftCardRejected('Gift cards are not accepted right now.');
        }

        $card = GiftCard::findByCode($input);

        if ($card === null) {
            throw new GiftCardRejected("We couldn't find that gift card. Check the code and try again.");
        }

        $problem = $card->problem((string) $cart->currency);

        if ($problem !== null) {
            throw new GiftCardRejected($problem);
        }

        $cart->forceFill(['gift_card_id' => $card->id])->save();
        $cart->setRelation('giftCard', $card);

        return $card;
    }

    public function remove(Cart $cart): void
    {
        $cart->forceFill(['gift_card_id' => null])->save();
        $cart->unsetRelation('giftCard');
    }

    /**
     * The card on the cart as the cart page shows it, or null when there is none. How much it will pay is
     * only known at checkout, once shipping and tax are, so the cart page says what the card holds.
     *
     * @return array{last_four: string, balance: string, applied: bool, problem: string|null}|null
     */
    public function describe(Cart $cart): ?array
    {
        if (! $this->cards->enabled()) {
            return null;
        }

        // Read afresh: the card may have been spent, or disabled, since the cart was loaded.
        $cart->unsetRelation('giftCard');
        $cart->loadMissing('giftCard');

        $card = $cart->giftCard;

        if ($card === null) {
            return null;
        }

        // What this cart's own unpaid checkout is holding is still its to spend.
        $held = $this->cards->heldByCart($cart);
        $problem = $card->problem((string) $cart->currency, $held->minor);

        return [
            'last_four' => $card->last_four,
            'balance' => Money::ofMinor($card->balanceMoney()->minor + $held->minor, (string) $cart->currency)->toDecimal(),
            'applied' => $problem === null,
            'problem' => $problem,
        ];
    }
}
