<?php

namespace App\Support\Billing;

use App\Models\BillingDunningNotice;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Sends a billing email once. Stripe delivers a webhook again when it is not answered in time, and an admin can replay one, so
 * the email is **claimed** first (one row in `billing_dunning_notices` per kind, invoice or subscription, and attempt) and sent
 * only by whoever managed to claim it.
 */
class BillingNotices
{
    /**
     * Claim the notice, and send it only if it was ours to claim. Both happen together: if sending fails the claim is undone, so
     * Stripe's next delivery of the same event tries again.
     */
    public function once(User $user, string $kind, string $reference, int $attempt, Notification $notification): void
    {
        DB::transaction(function () use ($user, $kind, $reference, $attempt, $notification) {
            $claimed = BillingDunningNotice::query()->insertOrIgnore([[
                'user_id' => $user->id,
                'kind' => $kind,
                'reference' => $reference,
                'attempt' => $attempt,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            if ($claimed === 0) {
                return;
            }

            $user->notify($notification);
        });
    }
}
