<?php

namespace App\Support\Commerce\GiftCards;

use RuntimeException;

/**
 * A gift card a shopper tried to use but cannot; the message says why, for the shopper.
 */
class GiftCardRejected extends RuntimeException {}
