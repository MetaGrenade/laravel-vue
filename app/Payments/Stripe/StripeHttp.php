<?php

namespace App\Payments\Stripe;

use Stripe\HttpClient\CurlClient;

/**
 * How long a call to Stripe may take before it is given up on (`STRIPE_TIMEOUT`, `STRIPE_CONNECT_TIMEOUT`, docs/configuration.md).
 *
 * The library waits 80 seconds for an answer and 30 to connect, and nothing in the application said otherwise. That is longer than a queue
 * worker lets a job run (60 seconds, docs/redis.md) and than most web servers wait for a page, so one call that never came back cost the
 * job or the request that made it: the worker killed the job, a visitor was shown the web server's gateway timeout. Cashier and the
 * shop's own calls all go through the library's one shared HTTP client, so setting the limits on it covers every call to Stripe.
 *
 * A call that is cut off fails like any other call that cannot be answered (an `ApiConnectionException`), which every caller already
 * handles: an order stays pending and is asked about again (docs/commerce.md), a refund is left for "Check status", a checkout is not
 * created and can be tried again with the same idempotency key.
 */
final class StripeHttp
{
    /** Seconds to wait for an answer, and to make the connection, when config/services.php does not say (it does, with these). */
    public const TIMEOUT = 15;

    public const CONNECT_TIMEOUT = 5;

    public static function configure(): void
    {
        // A key that is missing (a services.php written before these existed) gets these limits, and not 0, which would be no limit.
        CurlClient::instance()
            ->setTimeout((int) config('services.stripe.timeout', self::TIMEOUT))
            ->setConnectTimeout((int) config('services.stripe.connect_timeout', self::CONNECT_TIMEOUT));
    }
}
