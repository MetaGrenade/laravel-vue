<?php

namespace App\Support\Usage;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The stretch of time usage is added up over, and an allowance is for: a calendar month, in the application's time zone.
 *
 * It is not the subscription's own billing period. Those start on the day somebody subscribed and Cashier does not store them, so
 * lining the two up needs the dates from Stripe's webhooks; nothing here bills by them yet, and a calendar month is the same for
 * everybody, which is what a person reading "this month" expects. This one class is where that would change.
 */
final readonly class UsagePeriod
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    /**
     * The month that contains a moment (now, if none is given).
     */
    public static function monthOf(?CarbonInterface $moment = null): self
    {
        $start = CarbonImmutable::instance($moment ?? now())->startOfMonth();

        return new self($start, $start->addMonth());
    }

    /**
     * The month before this one.
     */
    public function previous(): self
    {
        return self::monthOf($this->start->subMonth());
    }

    public function contains(CarbonInterface $moment): bool
    {
        return $moment >= $this->start && $moment < $this->end;
    }
}
