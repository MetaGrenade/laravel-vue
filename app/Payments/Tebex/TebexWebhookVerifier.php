<?php

namespace App\Payments\Tebex;

use Illuminate\Http\Request;

/**
 * Whether a request really comes from Tebex.
 *
 * Two checks, both Tebex's own advice: it sends only from two addresses, and it signs the body. The signature is an
 * HMAC-SHA256, keyed with the endpoint's secret, of the SHA-256 hash (as hex) of the body exactly as it was sent: a
 * body that was parsed and written out again would not match.
 */
class TebexWebhookVerifier
{
    public function secret(): ?string
    {
        $secret = config('tebex.webhook.secret');

        return is_string($secret) && trim($secret) !== '' ? trim($secret) : null;
    }

    public function isConfigured(): bool
    {
        return $this->secret() !== null;
    }

    /**
     * Whether the request was sent from one of Tebex's addresses (always true when the check is switched off).
     */
    public function isFromTebex(Request $request): bool
    {
        if (! (bool) config('tebex.webhook.check_ip', true)) {
            return true;
        }

        return in_array($request->ip(), (array) config('tebex.webhook.ips', []), true);
    }

    /**
     * Whether the X-Signature header is the signature of the body, made with our secret.
     */
    public function isSigned(Request $request): bool
    {
        $secret = $this->secret();
        $given = $request->header('X-Signature');

        if ($secret === null || ! is_string($given) || $given === '') {
            return false;
        }

        return hash_equals(self::sign($request->getContent(), $secret), strtolower(trim($given)));
    }

    /**
     * The signature Tebex puts on a body.
     */
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', hash('sha256', $body), $secret);
    }
}
