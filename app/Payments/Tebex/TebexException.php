<?php

namespace App\Payments\Tebex;

use App\Payments\Exceptions\PaymentException;
use Throwable;

/**
 * A call to Tebex failed, or Tebex answered with something the shop cannot use. The message says what happened in
 * words a member of staff can act on; it never contains a key or a token.
 */
class TebexException extends PaymentException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether the credentials were refused, so that retrying cannot help and the keys need looking at.
     */
    public function isAuthentication(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
