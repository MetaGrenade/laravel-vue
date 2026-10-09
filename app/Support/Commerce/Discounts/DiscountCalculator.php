<?php

namespace App\Support\Commerce\Discounts;

use App\Enums\CouponType;
use App\Models\Cart;
use App\Models\Coupon;
use App\Support\Commerce\CustomerDetails;
use App\Support\Commerce\Money;
use App\Support\Commerce\PricedLine;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a discount code can be used on an order and, if so, what it takes off.
 *
 * This is the one definition of "valid": applying a code to a cart, the live quote on the checkout
 * page and the order that is placed all come here, so a code can never be accepted in one place
 * and refused in another. Money is worked out on integers, and the amount taken off the items is
 * shared between the lines it applies to by largest remainder, so the lines add up to it exactly.
 */
class DiscountCalculator
{
    private const NOT_VALID = "That code isn't valid.";

    public function __construct(private readonly CouponRedemptions $redemptions) {}

    /**
     * @param  list<PricedLine>  $lines  The cart, priced afresh (before any discount).
     * @param  Money  $shipping  The shipping charge chosen so far (zero when none is known yet).
     * @param  CustomerDetails|null  $customer  Who is buying, when known, for the per-customer limit.
     * @param  Cart|null  $cart  The cart being priced, so its own earlier unpaid order does not count as a use.
     * @param  bool  $shippingKnown  Whether `$shipping` is what will really be charged. Before the shopper has
     *                               given an address it is not known (zero is a placeholder), so free shipping
     *                               cannot yet be judged to save nothing.
     *
     * @throws CouponRejected
     */
    public function calculate(
        Coupon $coupon,
        array $lines,
        bool $needsShipping,
        Money $shipping,
        ?CustomerDetails $customer = null,
        ?Cart $cart = null,
        bool $shippingKnown = true,
    ): Discount {
        $currency = $shipping->currency;

        $this->assertUsable($coupon, $currency, $customer, $cart);

        $none = array_fill(0, count($lines), Money::zero($currency));

        if ($coupon->type === CouponType::FreeShipping) {
            if (! $needsShipping) {
                throw new CouponRejected('That code only applies to orders that are shipped.');
            }

            $this->assertMinimum($coupon, $this->subtotalOf($lines, array_keys($lines), $currency));

            // Nothing to waive: the order's shipping is already free. A use would be spent for no saving.
            if ($shippingKnown && $shipping->isZero()) {
                throw new CouponRejected('Shipping is already free on this order.');
            }

            return new Discount($coupon, Money::zero($currency), $shipping, $none);
        }

        $eligible = $this->eligibleLines($coupon, $lines);

        if ($eligible === []) {
            throw new CouponRejected("That code doesn't apply to anything in your cart.");
        }

        $eligibleSubtotal = $this->subtotalOf($lines, $eligible, $currency);

        $this->assertMinimum($coupon, $eligibleSubtotal);

        $total = $this->amountOff($coupon, $eligibleSubtotal);

        // A tiny percentage can round down to nothing. A code that saves nothing is not used, so it
        // never takes one of its limited uses.
        if ($total->isZero()) {
            throw new CouponRejected("That code wouldn't take anything off your cart.");
        }

        $shares = $total->allocate(array_map(fn (int $index) => $lines[$index]->subtotal->minor, $eligible));
        $perLine = $none;

        foreach ($eligible as $position => $index) {
            $perLine[$index] = $shares[$position];
        }

        return new Discount($coupon, $total, Money::zero($currency), $perLine);
    }

    /**
     * @throws CouponRejected
     */
    private function assertUsable(Coupon $coupon, string $currency, ?CustomerDetails $customer, ?Cart $cart): void
    {
        // A code that is switched off or has not started yet looks the same as one that does not exist.
        if (! $coupon->is_active || ($coupon->starts_at !== null && $coupon->starts_at->isFuture())) {
            throw new CouponRejected(self::NOT_VALID);
        }

        if ($coupon->ends_at !== null && $coupon->ends_at->isPast()) {
            throw new CouponRejected('That code has expired.');
        }

        // A fixed amount is in one currency: it is not worth the same number of anything else.
        if ($coupon->type === CouponType::Fixed && strtoupper((string) $coupon->currency) !== $currency) {
            throw new CouponRejected(self::NOT_VALID);
        }

        if ($coupon->max_redemptions !== null && $this->redemptions->total($coupon, $cart) >= $coupon->max_redemptions) {
            throw new CouponRejected('That code has been fully redeemed.');
        }

        if ($customer !== null
            && $coupon->max_redemptions_per_customer !== null
            && $this->redemptions->forCustomer($coupon, $customer, $cart) >= $coupon->max_redemptions_per_customer) {
            throw new CouponRejected("You've already used that code.");
        }
    }

    /**
     * @throws CouponRejected
     */
    private function assertMinimum(Coupon $coupon, Money $subtotal): void
    {
        if ($coupon->minimum_subtotal === null) {
            return;
        }

        $minimum = Money::parse($coupon->minimum_subtotal, $subtotal->currency);

        if ($subtotal->minor < $minimum->minor) {
            throw new CouponRejected("Spend {$minimum->toDecimal()} {$minimum->currency} or more to use that code.");
        }
    }

    /**
     * What comes off the eligible items: the percentage of them, or the fixed amount, never more
     * than they cost.
     *
     * @throws CouponRejected
     */
    private function amountOff(Coupon $coupon, Money $eligibleSubtotal): Money
    {
        if ($coupon->value === null) {
            throw new CouponRejected(self::NOT_VALID);
        }

        $amount = $coupon->type === CouponType::Percent
            ? $eligibleSubtotal->percent((string) $coupon->value)
            : Money::parse($coupon->value, $eligibleSubtotal->currency);

        return $amount->minor > $eligibleSubtotal->minor ? $eligibleSubtotal : $amount;
    }

    /**
     * The positions of the lines the code applies to: all of them, or those for the products or in
     * the categories it is limited to.
     *
     * Whether it is limited is the coupon's own flag, not whether any limits are left: the limits are
     * rows that go when their product or category is deleted, and a promotion scoped to one product must
     * not turn into a store-wide discount because that product was removed. With nothing left to match,
     * a limited code applies to nothing.
     *
     * @param  list<PricedLine>  $lines
     * @return list<int>
     */
    private function eligibleLines(Coupon $coupon, array $lines): array
    {
        if (! $coupon->is_restricted) {
            return array_keys($lines);
        }

        $productIds = DB::table('coupon_product')->where('coupon_id', $coupon->id)->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $categoryIds = DB::table('coupon_product_category')->where('coupon_id', $coupon->id)->pluck('product_category_id')->map(fn ($id) => (int) $id)->all();

        if ($productIds === [] && $categoryIds === []) {
            return [];
        }

        $inACategory = [];

        if ($categoryIds !== []) {
            $inACategory = DB::table('product_product_category')
                ->whereIn('product_id', array_map(fn (PricedLine $line) => $line->item->product_id, $lines))
                ->whereIn('product_category_id', $categoryIds)
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $eligible = [];

        foreach ($lines as $index => $line) {
            $productId = (int) $line->item->product_id;

            if (in_array($productId, $productIds, true) || in_array($productId, $inACategory, true)) {
                $eligible[] = $index;
            }
        }

        return $eligible;
    }

    /**
     * @param  list<PricedLine>  $lines
     * @param  list<int>  $positions
     */
    private function subtotalOf(array $lines, array $positions, string $currency): Money
    {
        return array_reduce(
            $positions,
            fn (Money $carry, int $index) => $carry->add($lines[$index]->subtotal),
            Money::zero($currency),
        );
    }
}
