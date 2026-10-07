<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns billable records (orders, payments, entitlements).
 *
 * Records carry an owner morph rather than a bare user id so a team can own
 * them in 1.1 without a rewrite. Code that asks "may this person see this
 * record" must go through here (or the policies built on it) instead of
 * comparing `user_id` directly. In 1.0 a person's only owner is themselves;
 * 1.1 adds the teams they belong to.
 */
final class Ownership
{
    /**
     * Every owner whose records this user may act on.
     *
     * @return list<Model>
     */
    public static function ownersFor(User $user): array
    {
        return [$user];
    }

    /**
     * The owner that new records created by this user belong to.
     */
    public static function newRecordOwnerFor(User $user): Model
    {
        return $user;
    }
}
