<?php

namespace App\Payments\Data;

use Carbon\CarbonInterface;

/**
 * A checkout created at a provider: where to send the customer and how to
 * recognise the payment later.
 */
final readonly class CheckoutSession
{
    /**
     * @param  array<string, mixed>  $raw  Provider payload kept on the payment for support.
     */
    public function __construct(
        public string $provider,
        public string $reference,
        public string $redirectUrl,
        public ?CarbonInterface $expiresAt = null,
        public array $raw = [],
    ) {}
}
