<?php

namespace App\Payments\Data;

/**
 * What to tell the provider after a webhook delivery. Anything but a 2xx makes
 * the provider redeliver, so only failures worth retrying are not 2xx.
 */
final readonly class WebhookOutcome
{
    private function __construct(
        public string $status,
        public int $httpStatus,
        public string $message,
    ) {}

    public static function handled(string $message = 'Webhook handled'): self
    {
        return new self('handled', 200, $message);
    }

    /**
     * Already processed on an earlier delivery.
     */
    public static function duplicate(): self
    {
        return new self('duplicate', 200, 'Webhook already handled');
    }

    /**
     * Understood but not something this shop acts on.
     */
    public static function ignored(string $message = 'Webhook ignored'): self
    {
        return new self('ignored', 200, $message);
    }

    /**
     * Not authentic (bad signature) or malformed. Not retried.
     */
    public static function rejected(string $message = 'Invalid webhook'): self
    {
        return new self('rejected', 400, $message);
    }

    /**
     * Processing failed; ask the provider to deliver it again.
     */
    public static function failed(string $message = 'Webhook processing failed'): self
    {
        return new self('failed', 500, $message);
    }
}
