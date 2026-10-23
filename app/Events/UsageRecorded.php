<?php

namespace App\Events;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A use was added to the usage ledger. Nothing in the shop listens: it is where to hook in what should follow every use, such as
 * reporting it to a Stripe billing meter (see docs/usage.md). It is not sent for a use that was already in the ledger.
 */
class UsageRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $meter,
        public readonly int $quantity,
        public readonly CarbonInterface $occurredAt,
        public readonly ?string $idempotencyKey,
    ) {}
}
