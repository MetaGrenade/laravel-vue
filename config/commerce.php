<?php

use App\Payments\Providers\StripeProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Store currency
    |--------------------------------------------------------------------------
    |
    | The ISO 4217 code the shop sells in. Only prices in this currency can be
    | added to a cart, so one order never mixes currencies. Three-decimal
    | currencies (KWD, BHD, ...) are not supported because prices are stored
    | with two decimal places.
    |
    */

    'currency' => strtoupper((string) env('COMMERCE_CURRENCY', env('CASHIER_CURRENCY', 'usd'))),

    /*
    |--------------------------------------------------------------------------
    | Payment provider
    |--------------------------------------------------------------------------
    |
    | The store-level provider that takes payment for every order. The ACP can
    | override this value (System settings). Each order records the provider it
    | was paid through, so changing it never affects orders already placed.
    |
    */

    'provider' => env('COMMERCE_PROVIDER', 'stripe'),

    'providers' => [
        'stripe' => StripeProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */

    'checkout' => [
        // Allow buying without an account.
        'guest' => (bool) env('COMMERCE_GUEST_CHECKOUT', true),

        // How long stock is held for an unpaid order before it is released.
        // Stripe Checkout sessions cannot expire sooner than 30 minutes.
        'payment_window_minutes' => max(30, (int) env('COMMERCE_PAYMENT_WINDOW', 60)),

        // Most units of one product or variant in a single cart line.
        'max_quantity' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Product images
    |--------------------------------------------------------------------------
    |
    | Uploads are decoded and re-encoded to WebP at three sizes (never enlarged), which
    | removes metadata and anything else hidden in the file; the original is not kept.
    | Images are public, so the disk must be one the web can serve ("public" needs
    | `php artisan storage:link`). Decoding needs memory proportional to the picture's
    | pixels (about four bytes each), so the pixel limit protects the server from a
    | small file that expands into a huge image.
    |
    */

    'images' => [
        'disk' => env('COMMERCE_IMAGE_DISK', 'public'),
        // Largest upload, in kilobytes.
        'max_kilobytes' => 5120,
        // Largest picture, in pixels (width x height). 16 million is a 4000 x 4000 photo.
        'max_pixels' => (int) env('COMMERCE_IMAGE_MAX_PIXELS', 16_000_000),
        'max_per_product' => 12,
        // The longest side of each kept size, in pixels.
        'sizes' => ['large' => 1600, 'medium' => 800, 'thumb' => 320],
        'quality' => 82,
    ],

    /*
    |--------------------------------------------------------------------------
    | Low stock
    |--------------------------------------------------------------------------
    |
    | A tracked product with this many left (or fewer) is shown as low on stock in
    | the ACP. It only labels; the shop keeps selling until it runs out.
    |
    */

    'low_stock_threshold' => max(0, (int) env('COMMERCE_LOW_STOCK_THRESHOLD', 5)),

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */

    'orders' => [
        // Prefix of the human-friendly order number, for example MF-000123.
        'number_prefix' => env('COMMERCE_ORDER_PREFIX', 'MF'),
    ],

];
