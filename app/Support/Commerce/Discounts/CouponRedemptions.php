<?php

namespace App\Support\Commerce\Discounts;

use App\Enums\OrderPaymentStatus;
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
 * carries the code and has not been cancelled or refunded in full, so an order that expires, is
 * cancelled or is returned to the customer gives its use back without anything having to remember
 * to. (A fulfilled order that is refunded in full stays "completed": only its payment status says
 * it was refunded, so both are looked at.)
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
     * The total is kept apart by the currency each order was placed in. If the shop's currency was ever
     * changed, a code can have orders in several, and adding a discount in one to a discount in another
     * would be a meaningless number.
     *
     * @return array{orders: int, discounted: list<array{currency: string, amount: string}>}
     */
    public function usage(Coupon $coupon): array
    {
        $orders = $this->uses($coupon)->get(['id', 'currency', 'discount_total']);

        $totals = [];

        foreach ($orders as $order) {
            $currency = strtoupper((string) $order->currency);
            $totals[$currency] = ($totals[$currency] ?? Money::zero($currency))->add(Money::parse($order->discount_total, $currency));
        }

        ksort($totals);

        return [
            'orders' => $orders->count(),
            'discounted' => array_map(
                fn (Money $money, string $currency) => ['currency' => $currency, 'amount' => $money->toDecimal()],
                $totals,
                array_keys($totals),
            ),
        ];
    }

    /**
     * How many uses each of several codes has, in one query, by the same rule as {@see self::total()}.
     *
     * @param  iterable<int>  $couponIds
     * @return array<int, int> Keyed by coupon id; codes with no uses are absent.
     */
    public function totals(iterable $couponIds): array
    {
        return Order::query()
            ->whereIn('coupon_id', collect($couponIds)->all())
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('payment_status', '!=', OrderPaymentStatus::Refunded->value)
            ->groupBy('coupon_id')
            ->selectRaw('coupon_id, count(*) as uses')
            ->pluck('uses', 'coupon_id')
            ->map(fn ($uses) => (int) $uses)
            ->all();
    }

    /**
     * @return Builder<Order>
     */
    private function uses(Coupon $coupon, ?Cart $cart = null): Builder
    {
        return Order::query()
            ->where('coupon_id', $coupon->id)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('payment_status', '!=', OrderPaymentStatus::Refunded->value)
            // Not "NOT (cart is this one AND pending)": an order with no cart (a deleted one) makes
            // that comparison NULL, which would drop it from the count.
            ->when($cart !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($cart) {
                $query->where('status', '!=', OrderStatus::Pending->value)
                    ->orWhereNull('cart_id')
                    ->orWhere('cart_id', '!=', $cart->id);
            }));
    }
}
