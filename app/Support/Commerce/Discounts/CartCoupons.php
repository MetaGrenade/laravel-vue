<?php

namespace App\Support\Commerce\Discounts;

use App\Enums\CouponType;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\User;
use App\Support\Commerce\CheckoutException;
use App\Support\Commerce\CustomerDetails;
use App\Support\Commerce\OrderPricer;
use App\Support\Commerce\Pricing;

/**
 * The discount code on a shopper's cart: putting one on, taking it off, and describing it for the
 * cart page.
 *
 * A code is accepted by pricing the cart with it exactly as checkout will, so "accepted here"
 * and "accepted at checkout" cannot disagree. Accepting a code reserves nothing: it is checked
 * again whenever the cart is priced, and counted as used only once an order carries it.
 */
class CartCoupons
{
    public function __construct(private readonly OrderPricer $pricer) {}

    /**
     * @throws CouponRejected When the code does not exist or cannot be used on this cart.
     * @throws CheckoutException When something in the cart cannot be bought, so it cannot be priced.
     */
    public function apply(Cart $cart, string $code, ?User $user = null): Coupon
    {
        $code = CouponCode::normalise($code);
        $coupon = CouponCode::isWellFormed($code) ? Coupon::findByCode($code) : null;

        if ($coupon === null) {
            throw new CouponRejected("That code isn't valid.");
        }

        $cart->setRelation('coupon', $coupon);
        $pricing = $this->pricer->price($cart, customer: $this->customerFor($user));

        if ($pricing->couponProblem !== null) {
            $cart->unsetRelation('coupon');

            throw new CouponRejected($pricing->couponProblem);
        }

        $cart->forceFill(['coupon_id' => $coupon->id])->save();

        return $coupon;
    }

    public function remove(Cart $cart): void
    {
        $cart->forceFill(['coupon_id' => null])->save();
        $cart->unsetRelation('coupon');
    }

    /**
     * What the cart costs now, with its code applied, for the cart page: priced the way checkout will
     * price it, but before an address is known. Null when something in the cart cannot be bought.
     */
    public function price(Cart $cart, ?User $user = null): ?Pricing
    {
        // Read afresh: the code may have been removed, or deleted, since the cart was loaded.
        $cart->unsetRelation('coupon');

        try {
            return $this->pricer->price($cart, customer: $this->customerFor($user));
        } catch (CheckoutException) {
            return null;
        }
    }

    /**
     * The code on the cart as the cart page shows it, or null when there is none.
     *
     * @param  Pricing|null  $pricing  The cart as {@see self::price()} returned it.
     * @return array{code: string, applied: bool, problem: string|null, discount: string|null, free_shipping: bool}|null
     */
    public function describe(Cart $cart, ?Pricing $pricing): ?array
    {
        $cart->loadMissing('coupon');

        if ($cart->coupon === null) {
            return null;
        }

        if ($pricing === null) {
            // Something in the cart is unavailable: the cart page says so, and the code waits.
            return ['code' => $cart->coupon->code, 'applied' => false, 'problem' => null, 'discount' => null, 'free_shipping' => false];
        }

        return [
            'code' => $cart->coupon->code,
            'applied' => $pricing->applied !== null,
            'problem' => $pricing->couponProblem,
            'discount' => $pricing->applied !== null ? $pricing->discount->toDecimal() : null,
            'free_shipping' => $cart->coupon->type === CouponType::FreeShipping,
        ];
    }

    private function customerFor(?User $user): ?CustomerDetails
    {
        return $user === null ? null : new CustomerDetails(email: $user->email, name: $user->nickname, user: $user);
    }
}
