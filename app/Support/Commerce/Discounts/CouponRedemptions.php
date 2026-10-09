<?php

namespace App\Support\Commerce\Discounts;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Support\Commerce\CustomerDetails;
use App\Support\Commerce\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * How many times a discount code has been used. Nothing is stored for this: a use is an order that
 * carries the code and has not been cancelled, so an order that expires, is cancelled or is
 * refunded and cancelled gives its use back without anything having to remember to.
 *
 * Orders still waiting for payment count, because they hold the use until they are paid or expire;
 * the one exception is the shopper's own earlier attempt from the same cart, which the next
 * checkout replaces.
 */
class CouponRedemptions
{
    public function total(Coupon $coupon, ?Cart $cart = null): int
    {
        return $this->uses($coupon, $cart)->count();
    }

    /**
     * How many times this customer has used the code: by their account when they have one, and by
     * the email on the order (a guest, or the same person before they registered).
     */
    public function forCustomer(Coupon $coupon, CustomerDetails $customer, ?Cart $cart = null): int
    {
        return $this->uses($coupon, $cart)
            ->where(function (Builder $query) use ($customer) {
                $query->whereRaw('lower(customer_email) = ?', [Str::lower(trim($customer->email))]);

                if ($customer->user !== null) {
                    $query->orWhere('user_id', $customer->user->id);
                }
            })
            ->count();
    }

    /**
     * What the code has done so far, for staff: orders it is on and the total taken off them.
     *
     * @return array{orders: int, discounted: string}
     */
    public function usage(Coupon $coupon, string $currency): array
    {
        $orders = $this->uses($coupon)->get(['id', 'discount_total']);

        $discounted = $orders->reduce(
            fn (Money $carry, Order $order) => $carry->add(Money::parse($order->discount_total, $currency)),
            Money::zero($currency),
        );

        return ['orders' => $orders->count(), 'discounted' => $discounted->toDecimal()];
    }

    /**
     * @return Builder<Order>
     */
    private function uses(Coupon $coupon, ?Cart $cart = null): Builder
    {
        return Order::query()
            ->where('coupon_id', $coupon->id)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            // Not "NOT (cart is this one AND pending)": an order with no cart (a deleted one) makes
            // that comparison NULL, which would drop it from the count.
            ->when($cart !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($cart) {
                $query->where('status', '!=', OrderStatus::Pending->value)
                    ->orWhereNull('cart_id')
                    ->orWhere('cart_id', '!=', $cart->id);
            }));
    }
}
