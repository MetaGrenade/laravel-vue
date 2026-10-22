<?php

namespace App\Support\Usage;

use App\Events\UsageRecorded;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Metered usage: what is counted, how much of it an account has used this month, and how much it may (see docs/usage.md).
 *
 * `record()` is the one call that code makes to count something; everything else here reads the ledger it writes.
 */
class Usage
{
    public function __construct(private readonly Meters $meters) {}

    /**
     * Count a use. Several can be counted at once (`$quantity`), and a use given a key is counted once however often it is reported.
     *
     * @param  string|null  $key  The caller's own key for this use (a request id, an order line): the same key for the same account and
     *                            meter is not counted again.
     * @param  int|null  $tokenId  The API token it was made with, if any.
     * @return bool Whether it was counted (false: it was already in the ledger under that key).
     *
     * @throws InvalidArgumentException a meter that does not exist, or a quantity or key that cannot be counted
     */
    public function record(User $user, string $meter, int $quantity = 1, ?string $key = null, ?CarbonInterface $at = null, ?int $tokenId = null): bool
    {
        $this->meters->get($meter);

        if ($quantity < 1) {
            throw new InvalidArgumentException('A use is counted as one or more; ['.$quantity.'] cannot be.');
        }

        if ($key !== null && strlen($key) > 100) {
            throw new InvalidArgumentException('A usage key is at most 100 characters.');
        }

        $at ??= now();

        // Ignoring a duplicate is portable (INSERT OR IGNORE / INSERT IGNORE / ON CONFLICT DO NOTHING) and, unlike catching the unique
        // violation, leaves a PostgreSQL transaction the caller is in usable.
        $counted = DB::table('usage_events')->insertOrIgnore([
            'user_id' => $user->getKey(),
            'meter' => $meter,
            'quantity' => $quantity,
            'occurred_at' => $at,
            'idempotency_key' => $key,
            'token_id' => $tokenId,
            'created_at' => now(),
        ]) === 1;

        if ($counted) {
            UsageRecorded::dispatch($user, $meter, $quantity, $at, $key);
        }

        return $counted;
    }

    /**
     * How much the account has used of a meter in a month (this one, if none is given).
     */
    public function used(User $user, string $meter, ?UsagePeriod $period = null): int
    {
        $this->meters->get($meter);

        $period ??= UsagePeriod::monthOf();

        return (int) UsageEvent::query()
            ->where('user_id', $user->getKey())
            ->where('meter', $meter)
            ->where('occurred_at', '>=', $period->start)
            ->where('occurred_at', '<', $period->end)
            ->sum('quantity');
    }

    /**
     * How much of a meter the account may use each month: what its plans give, otherwise the meter's free allowance. Null means no limit.
     */
    public function allowance(User $user, string $meter): ?int
    {
        $definition = $this->meters->get($meter);

        if ($definition->entitlement !== null && $user->canUse($definition->entitlement)) {
            return $user->limitOf($definition->entitlement);
        }

        return $definition->freeAllowance;
    }

    /**
     * Where the account stands on a meter this month (or another month, if one is given).
     */
    public function status(User $user, string $meter, ?UsagePeriod $period = null): UsageStatus
    {
        $period ??= UsagePeriod::monthOf();

        return new UsageStatus($this->meters->get($meter), $this->used($user, $meter, $period), $this->allowance($user, $meter), $period);
    }

    /**
     * Where the account stands on every meter this month, for a page.
     *
     * @return list<array{meter: string, label: string, unit: string, used: int, allowance: int|null, remaining: int|null, exhausted: bool, blocks: bool, resets_at: string}>
     */
    public function summary(User $user): array
    {
        $period = UsagePeriod::monthOf();

        return array_map(
            fn (string $key) => $this->status($user, $key, $period)->toArray(),
            array_keys($this->meters->all()),
        );
    }
}
