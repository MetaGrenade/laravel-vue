<?php

namespace App\Payments\Tebex;

/**
 * What a buyer coming through the sign-in link should be shown or sent to next.
 */
final readonly class SignInStep
{
    public const REDIRECT = 'redirect';

    public const CHOOSE = 'choose';

    public const FAILED = 'failed';

    /** The basket is ready to be paid and the buyer pays on the shop's own page (Tebex.js), not Tebex's. */
    public const PAY = 'pay';

    /**
     * @param  list<array{name: string, url: string}>  $options
     */
    private function __construct(
        public string $kind,
        public ?string $url = null,
        public array $options = [],
        public ?string $message = null,
        public ?string $ident = null,
    ) {}

    /**
     * Show the buyer the shop's own payment page for this basket. The address is Tebex's own page for it, kept as the
     * way to pay if Tebex.js cannot be used.
     */
    public static function pay(string $ident, string $hostedUrl): self
    {
        return new self(self::PAY, url: $hostedUrl, ident: $ident);
    }

    /**
     * Send the buyer on: to sign in, or (once they have) to pay.
     */
    public static function redirect(string $url): self
    {
        return new self(self::REDIRECT, url: $url);
    }

    /**
     * Tebex offers more than one way to sign in: the buyer picks.
     *
     * @param  list<array{name: string, url: string}>  $options
     */
    public static function choose(array $options): self
    {
        return new self(self::CHOOSE, options: $options);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, message: $message);
    }
}
