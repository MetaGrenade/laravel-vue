<?php

namespace App\Payments\Data;

use Carbon\CarbonInterface;

/**
 * What the provider needs to know about where the customer comes from and
 * goes back to.
 */
final readonly class CheckoutContext
{
    public function __construct(
        public string $successUrl,
        public string $cancelUrl,
        public CarbonInterface $expiresAt,
        public ?string $locale = null,
    ) {}
}
