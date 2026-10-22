<?php

namespace App\Support\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Commerce\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Enums\StripeApiVersions;
use Stripe\Coupon;
use Stripe\Exception\ApiErrorException;
use Stripe\PromotionCode;
use Stripe\StripeClient;

/**
 * Looks a promotion code up at Stripe before anybody pays, so a customer is told at once if a code is wrong, and what it takes
 * off if it is right. The codes themselves (their discount, expiry, limits and who may use them) are made and kept at Stripe;
 * nothing about them is stored here.
 *
 * What can be seen beforehand is checked: that Stripe has the code and it is live, that it is not for another customer, and
 * that it takes something off this plan. What Stripe holds a code to when it is used (first-time customers only, a minimum
 * payment) is shown as a note and left to Stripe, and a refusal at that point is reported just the same
 * (see {@see SubscriptionManager}).
 */
class PromotionCodes
{
    /**
     * @throws PromotionCodeRejected the code cannot be used, or could not be checked
     */
    public function find(User $user, SubscriptionPlan $plan, string $code): PromotionOffer
    {
        $code = trim($code);

        // What Stripe allows in a code is letters, digits and dashes. Anything else cannot be one, and need not be sent.
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $code) !== 1) {
            throw PromotionCodeRejected::notValid();
        }

        try {
            $stripe = Cashier::stripe();

            // Case-insensitive at Stripe, and only the ones that can still be redeemed (not expired, used up or archived).
            $promotion = $this->forThisCustomer($user, $stripe->promotionCodes->all(['code' => $code, 'active' => true, 'limit' => 100])->data);

            if ($promotion === null) {
                throw PromotionCodeRejected::notValid();
            }

            $coupon = $this->couponOf($stripe, $promotion);
            $amountOff = $this->mustSuit($stripe, $plan, $coupon);

            return new PromotionOffer(
                $promotion->id,
                $promotion->code,
                $this->summary($coupon, $amountOff, $plan->currency),
                $this->notes($promotion, $plan->currency),
            );
        } catch (ApiErrorException $exception) {
            Log::warning('Could not check a promotion code with Stripe.', ['user_id' => $user->getKey(), 'error' => $exception->getMessage()]);

            throw PromotionCodeRejected::cannotBeChecked();
        }
    }

    /**
     * The code for this customer out of those Stripe has under that name: one made for them in particular if there is one,
     * otherwise the one anybody may use. A code made for somebody else is theirs alone, and is not offered.
     *
     * @param  array<int, PromotionCode>  $candidates
     */
    private function forThisCustomer(User $user, array $candidates): ?PromotionCode
    {
        $anybodys = null;

        foreach ($candidates as $candidate) {
            $customer = $this->idOf($candidate->customer ?? null);

            if ($customer !== null && $customer === $user->stripe_id) {
                return $candidate;
            }

            // A code can also be made for an account that stands for a customer; whoever it is, it is not this customer.
            if ($customer === null && ($candidate->customer_account ?? null) === null) {
                $anybodys ??= $candidate;
            }
        }

        return $anybodys;
    }

    private function couponOf(StripeClient $stripe, PromotionCode $promotion): Coupon
    {
        $coupon = StripeApiVersions::current()->couponFromPromotionCode($promotion);
        $id = $this->idOf($coupon);

        if ($id === null) {
            throw PromotionCodeRejected::notValid();
        }

        // The products it is limited to are only sent when asked for.
        return $stripe->coupons->retrieve($id, ['expand' => ['applies_to']]);
    }

    /**
     * Whether the coupon behind a code takes anything off this plan; says why not if it does not.
     *
     * @return int|null what it takes off each payment in the plan's currency, when it is an amount rather than a percentage
     */
    private function mustSuit(StripeClient $stripe, SubscriptionPlan $plan, Coupon $coupon): ?int
    {
        $data = $coupon->toArray();

        $amountOff = null;

        if (Arr::get($data, 'amount_off') !== null) {
            // Cashier will not start a subscription with a coupon like this (see its SubscriptionBuilder), and a code is no different.
            if (Arr::get($data, 'duration') === 'forever') {
                throw PromotionCodeRejected::because("That code can't be used on a subscription.");
            }

            $amountOff = $this->amountOffIn($data, $plan->currency)
                ?? throw PromotionCodeRejected::because("That code can't be used with this plan's currency.");
        }

        $products = Arr::get($data, 'applies_to.products', []);

        // A coupon limited to some products takes nothing off a plan that is not one of them, and Stripe says nothing about it.
        if (is_array($products) && $products !== []) {
            $price = $stripe->prices->retrieve($plan->stripe_price_id)->toArray();
            $product = $price['product'] ?? null;
            $product = is_array($product) ? ($product['id'] ?? null) : $product;

            if (! in_array($product, $products, true)) {
                throw PromotionCodeRejected::because("That code doesn't apply to the {$plan->name} plan.");
            }
        }

        return $amountOff;
    }

    /**
     * What an amount-off coupon takes off in a currency: its own, or one it was given a different amount for.
     *
     * @param  array<string, mixed>  $coupon
     */
    private function amountOffIn(array $coupon, string $currency): ?int
    {
        $currency = strtolower($currency);

        if (strtolower((string) Arr::get($coupon, 'currency')) === $currency) {
            return (int) $coupon['amount_off'];
        }

        $other = Arr::get($coupon, "currency_options.{$currency}.amount_off");

        return $other === null ? null : (int) $other;
    }

    private function summary(Coupon $coupon, ?int $amountOff, string $currency): string
    {
        $off = $amountOff !== null
            ? Money::ofMinor($amountOff, $currency)->toDecimal().' '.strtoupper($currency)
            : rtrim(rtrim(number_format((float) $coupon->percent_off, 2, '.', ''), '0'), '.').'%';

        $months = (int) $coupon->duration_in_months;

        return $off.' off '.match ($coupon->duration) {
            'repeating' => 'for '.$months.($months === 1 ? ' month' : ' months'),
            'forever' => 'every payment',
            default => 'your first payment',
        };
    }

    /**
     * What Stripe holds the code to when it is used, said as it is stored.
     *
     * @return list<string>
     */
    private function notes(PromotionCode $promotion, string $currency): array
    {
        $restrictions = (array) Arr::get($promotion->toArray(), 'restrictions', []);
        $notes = [];

        if (Arr::get($restrictions, 'first_time_transaction') === true) {
            $notes[] = 'Only for first-time customers.';
        }

        if (Arr::get($restrictions, 'minimum_amount') !== null) {
            $minimum = $this->minimumIn($restrictions, $currency);

            $notes[] = $minimum === null
                ? 'Has a minimum payment.'
                : 'Needs a first payment of at least '.Money::ofMinor($minimum, $currency)->toDecimal().' '.strtoupper($currency).'.';
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed>  $restrictions
     */
    private function minimumIn(array $restrictions, string $currency): ?int
    {
        $currency = strtolower($currency);

        if (strtolower((string) Arr::get($restrictions, 'minimum_amount_currency')) === $currency) {
            return (int) $restrictions['minimum_amount'];
        }

        $other = Arr::get($restrictions, "currency_options.{$currency}.minimum_amount");

        return $other === null ? null : (int) $other;
    }

    /**
     * The id of something Stripe sends either as its id or in full.
     */
    private function idOf(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_object($value) && isset($value->id) ? (string) $value->id : null;
    }
}
