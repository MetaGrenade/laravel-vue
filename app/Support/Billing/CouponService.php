<?php

namespace App\Support\Billing;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function preview(string $code, SubscriptionPlan $plan, ?User $user): array
    {
        $coupon = $this->eligibleCoupon($code, $plan, $user);

        $discount = $coupon->discountAmountFor($plan->price);
        $discount = min($discount, $plan->price);

        $bonusTrialDays = max($coupon->bonus_trial_days, 0);

        return [
            'model' => $coupon,
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'name' => $coupon->name,
            ],
            'discount_amount' => $discount,
            'plan_price' => $plan->price,
            'total' => max($plan->price - $discount, 0),
            'trial_days' => $plan->trial_days + $bonusTrialDays,
            'bonus_trial_days' => $bonusTrialDays,
        ];
    }

    public function markRedeemed(Coupon $coupon, User $user, int $discount, int $trialDays, ?int $subscriptionId = null): void
    {
        CouponRedemption::updateOrCreate(
            [
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
            ],
            [
                'subscription_id' => $subscriptionId,
                'discount_amount' => $discount,
                'trial_days' => $trialDays,
                'redeemed_at' => Carbon::now(),
            ],
        );

        if ($coupon->max_redemptions !== null && $coupon->redeemed_count < $coupon->max_redemptions) {
            $coupon->increment('redeemed_count');
        }
    }

    protected function eligibleCoupon(string $code, SubscriptionPlan $plan, ?User $user): Coupon
    {
        $normalizedCode = strtoupper(trim($code));

        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()
            ->whereRaw('upper(code) = ?', [$normalizedCode])
            ->first();

        if (! $coupon) {
            throw ValidationException::withMessages([
                'coupon' => 'The promo code you entered is not recognized.',
            ]);
        }

        if (! $coupon->is_active) {
            throw ValidationException::withMessages([
                'coupon' => 'This promo code is not currently active.',
            ]);
        }

        if ($coupon->isExpired()) {
            throw ValidationException::withMessages([
                'coupon' => 'This promo code has expired.',
            ]);
        }

        if ($coupon->subscription_plan_id && $coupon->subscription_plan_id !== $plan->id) {
            throw ValidationException::withMessages([
                'coupon' => 'This promo code cannot be used with the selected plan.',
            ]);
        }

        if ($coupon->min_amount > 0 && $plan->price < $coupon->min_amount) {
            throw ValidationException::withMessages([
                'coupon' => 'The plan price does not meet the promo code requirements.',
            ]);
        }

        if ($coupon->max_redemptions !== null && $coupon->redeemed_count >= $coupon->max_redemptions) {
            throw ValidationException::withMessages([
                'coupon' => 'This promo code has reached its redemption limit.',
            ]);
        }

        if ($user && $coupon->redemptions()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'coupon' => 'You have already used this promo code.',
            ]);
        }

        return $coupon;
    }
}
