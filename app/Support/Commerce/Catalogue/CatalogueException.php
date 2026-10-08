<?php

namespace App\Support\Commerce\Catalogue;

use RuntimeException;

/**
 * A catalogue change that is not allowed. The message is written for the staff
 * member who asked, and `field` names the form field it belongs to, if any.
 */
class CatalogueException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
