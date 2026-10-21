<?php

namespace App\Payments\Tebex;

use App\Models\Cart;
use App\Payments\PaymentManager;
use App\Payments\Providers\TebexProvider;
use App\Support\Commerce\CartManager;

/**
 * What a shop that takes payment through Tebex sells.
 *
 * There are two ways, and a shop is in one:
 *
 * - **Packages** (the Headless API, the default): Tebex keeps the catalogue, the shop shows a copy of it and the cart
 *   holds Tebex packages.
 * - **Products** (the Checkout API, `TEBEX_CHECKOUT_API_ENABLED`, experimental): the catalogue is the shop's own, the cart
 *   holds its products, and Tebex only takes the payment. See {@see TebexCheckoutApi}.
 *
 * Either way Tebex is the merchant of record, so a cart that is paid through it is priced without the shop's own
 * codes, gift cards, shipping and tax: the single place that says whether a cart is one is {@see self::paidThroughTebex()}.
 */
final class TebexSelling
{
    /**
     * Whether the Checkout API is switched on, which makes the Tebex provider sell the shop's own products.
     */
    public static function checkoutApi(): bool
    {
        return (bool) config('tebex.checkout_api.enabled', false);
    }

    /**
     * Whether a shop whose payment provider is `$activeKey` sells Tebex packages.
     */
    public static function packages(string $activeKey): bool
    {
        return $activeKey === TebexProvider::KEY && ! self::checkoutApi();
    }

    /**
     * Whether a shop whose payment provider is `$activeKey` sells its own products and takes their payment through Tebex.
     */
    public static function products(string $activeKey): bool
    {
        return $activeKey === TebexProvider::KEY && self::checkoutApi();
    }

    /**
     * Whether Tebex prices, discounts, taxes and takes the payment for what is in the cart: packages, or products in a
     * shop that sells them through Tebex.
     */
    public static function paidThroughTebex(?Cart $cart): bool
    {
        return match (CartManager::kind($cart)) {
            CartManager::PACKAGES => true,
            CartManager::PRODUCTS => self::products(app(PaymentManager::class)->activeKey()),
            default => false,
        };
    }
}
