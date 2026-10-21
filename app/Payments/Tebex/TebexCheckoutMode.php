<?php

namespace App\Payments\Tebex;

/**
 * Where the buyer pays for a Tebex order.
 *
 * Tebex's hosted page is the default and the fallback for the other two, which use Tebex.js to keep the buyer on the
 * shop's own page (see docs/tebex.md): in a popup over it, or inside it. Whichever is chosen, nothing the browser does
 * settles an order. Only Tebex's webhook does.
 */
enum TebexCheckoutMode: string
{
    case Redirect = 'redirect';
    case Popup = 'popup';
    case Inline = 'inline';

    /**
     * The mode the shop is set to (`TEBEX_CHECKOUT_MODE`). Anything it does not know is the hosted page: a mistyped
     * setting must not leave buyers with no way to pay.
     */
    public static function current(): self
    {
        return self::tryFrom(strtolower(trim((string) config('tebex.checkout.mode', self::Redirect->value)))) ?? self::Redirect;
    }

    /**
     * Whether the buyer pays on the shop's own page, with Tebex.js.
     */
    public function isEmbedded(): bool
    {
        return $this !== self::Redirect;
    }
}
