<?php

namespace App\Payments\Tebex;

use App\Payments\Exceptions\PaymentException;

/**
 * Another request is changing this basket at Tebex and did not finish in the time allowed. Nothing was done to the
 * basket; trying again in a moment is the answer.
 */
class TebexBasketBusy extends PaymentException {}
