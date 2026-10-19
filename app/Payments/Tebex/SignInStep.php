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

    /**
     * @param  list<array{name: string, url: string}>  $options
     */
    private function __construct(
        public string $kind,
        public ?string $url = null,
        public array $options = [],
        public ?string $message = null,
    ) {}

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
