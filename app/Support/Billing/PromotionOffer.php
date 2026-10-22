<?php

namespace App\Support\Billing;

/**
 * A promotion code that Stripe knows and that can be used on a plan: what it is called at Stripe, what it takes off, and any
 * conditions Stripe will check when it is used.
 */
final readonly class PromotionOffer
{
    /**
     * @param  string  $id  Stripe's id for the promotion code (what a subscription is started with).
     * @param  string  $code  The code as the customer knows it.
     * @param  string  $summary  What it takes off, e.g. "20% off for 3 months".
     * @param  list<string>  $notes  Conditions Stripe holds it to that this app cannot check beforehand.
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $summary,
        public array $notes = [],
    ) {}

    /**
     * What the page is told. Stripe's id stays on the server.
     *
     * @return array{code: string, summary: string, notes: list<string>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'summary' => $this->summary, 'notes' => $this->notes];
    }
}
