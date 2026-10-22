<?php

namespace App\Support\Billing;

/**
 * What came of asking Stripe to take a subscription's overdue payment now.
 */
final readonly class PaymentRetry
{
    /** The payment went through. */
    public const PAID = 'paid';

    /** The card needs the customer (3-D Secure): `$url` is Stripe's page for the invoice, where they can do it. */
    public const ACTION_REQUIRED = 'action_required';

    /** The bank or card refused it. */
    public const DECLINED = 'declined';

    /** There was nothing overdue to pay. */
    public const NOTHING_DUE = 'nothing_due';

    /** Stripe could not be reached, so nothing was tried. */
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public string $status,
        public ?string $message = null,
        public ?string $url = null,
    ) {}

    public static function paid(): self
    {
        return new self(self::PAID);
    }

    public static function actionRequired(string $url): self
    {
        return new self(self::ACTION_REQUIRED, url: $url);
    }

    public static function declined(string $message): self
    {
        return new self(self::DECLINED, message: $message);
    }

    public static function nothingDue(): self
    {
        return new self(self::NOTHING_DUE);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }
}
