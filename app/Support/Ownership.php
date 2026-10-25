<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns billable records (orders, payments, entitlements).
 *
 * Records carry an owner morph rather than a bare user id so a team can own
 * them in 1.1 without a rewrite. Code that asks "may this person see this
 * record" must go through here (or the policies built on it) instead of
 * comparing `user_id` directly. In 1.0 a person's only owner is themselves;
 * 1.1 adds the teams they belong to (see docs/ownership.md).
 */
final class Ownership
{
    /** @var (Closure(User): iterable<Model>)|null */
    private static ?Closure $owners = null;

    /** @var (Closure(User): Model)|null */
    private static ?Closure $newRecordOwner = null;

    /**
     * Every owner whose records this user may act on.
     *
     * @return list<Model>
     */
    public static function ownersFor(User $user): array
    {
        if (self::$owners === null) {
            return [$user];
        }

        $owners = [];

        foreach ((self::$owners)($user) as $owner) {
            $owners[] = $owner;
        }

        return $owners;
    }

    /**
     * The owner that new records created by this user belong to.
     */
    public static function newRecordOwnerFor(User $user): Model
    {
        return self::$newRecordOwner !== null ? (self::$newRecordOwner)($user) : $user;
    }

    /**
     * Where teams plug in (1.1): say who else a user acts for, and who owns what they create. Nothing in 1.0 calls it. The tests
     * do, with a stand-in for a team, to prove that every policy, scope and lookup follows the owners given here and nothing else.
     *
     * @param  Closure(User): iterable<Model>  $owners  Every owner whose records the user may act on (their own account included).
     * @param  (Closure(User): Model)|null  $newRecordOwner  Who a record the user creates belongs to; the user themselves if left out.
     */
    public static function resolveUsing(Closure $owners, ?Closure $newRecordOwner = null): void
    {
        self::$owners = $owners;
        self::$newRecordOwner = $newRecordOwner;
    }

    /**
     * Back to 1.0: a person is their own only owner.
     */
    public static function flush(): void
    {
        self::$owners = null;
        self::$newRecordOwner = null;
    }
}
