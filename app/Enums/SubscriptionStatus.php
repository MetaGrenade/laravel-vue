<?php

namespace App\Enums;

/**
 * Where a recurring payment kept at a provider stands, in terms that do not depend on which provider it is.
 *
 * Whether it has *ended* is a separate fact (`ended_at`): a subscription whose cancellation was asked for is still
 * active until the end of what was paid for, and one that has expired or been cancelled is over.
 */
enum SubscriptionStatus: string
{
    /** Paid up, and will renew. */
    case Active = 'active';

    /** A renewal could not be taken. The provider tries again, so it is not over yet. */
    case Overdue = 'overdue';

    /** The provider gave up after repeated failures to take a renewal. */
    case Expired = 'expired';

    /** Cancelled, by the customer or the store. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Overdue => 'Payment overdue',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether it still runs: the provider will keep (or try to keep) taking payments for it.
     */
    public function isLive(): bool
    {
        return $this === self::Active || $this === self::Overdue;
    }

    /**
     * Tebex's own status ids (the `status.id` of a recurring payment): 2 Active, 3 Overdue, 4 Expired, 5 Cancelled and
     * 7 Pending Downgrade, which is still active (the customer asked to move to a cheaper package at the next renewal).
     * Null for one it does not know, so the status the shop has is kept rather than guessed at.
     */
    public static function fromTebex(?int $id): ?self
    {
        return match ($id) {
            2, 7 => self::Active,
            3 => self::Overdue,
            4 => self::Expired,
            5 => self::Cancelled,
            default => null,
        };
    }
}
