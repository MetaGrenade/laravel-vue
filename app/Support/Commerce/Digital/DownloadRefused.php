<?php

namespace App\Support\Commerce\Digital;

use RuntimeException;

/**
 * A download that cannot be served. The message is for the customer and is safe to show as it is;
 * `status` is the HTTP status that fits (the link is wrong, or it has run out, or it is not allowed).
 */
class DownloadRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 403)
    {
        parent::__construct($message);
    }
}
