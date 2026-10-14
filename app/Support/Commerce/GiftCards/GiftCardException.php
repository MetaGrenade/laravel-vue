<?php

namespace App\Support\Commerce\GiftCards;

use RuntimeException;

/**
 * A gift card change that cannot be made; the message is fit to show whoever asked.
 */
class GiftCardException extends RuntimeException {}
