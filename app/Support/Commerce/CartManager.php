<?php

namespace App\Support\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\TebexPackage;
use Illuminate\Http\Request;

class CartManager
{
    public const OPEN = 'open';

    public const CONVERTED = 'converted';

    /** What a cart holds: the shop's own products, or packages from the Tebex catalogue (never both). */
    public const PRODUCTS = 'products';

    public const PACKAGES = 'packages';

    public const MIXED = 'mixed';

    public const EMPTY = 'empty';

    /**
     * What the cart holds. A cart of packages is bought through Tebex and a cart of products is not, so the two cannot
     * share one.
     */
    public static function kind(?Cart $cart): string
    {
        if ($cart === null) {
            return self::EMPTY;
        }

        $cart->loadMissing('items');

        $packages = $cart->items->filter(fn (CartItem $item) => $item->isTebexPackage())->count();

        return match (true) {
            $cart->items->isEmpty() => self::EMPTY,
            $packages === 0 => self::PRODUCTS,
            $packages === $cart->items->count() => self::PACKAGES,
            default => self::MIXED,
        };
    }

    /**
     * The shopper's open cart: their account's cart when signed in, otherwise
     * the one tied to this browser session. A cart that has become an order is
     * never returned.
     */
    public static function forRequest(Request $request, bool $create = false): ?Cart
    {
        $sessionId = $request->session()->getId();
        $userId = $request->user()?->id;

        $cart = Cart::query()
            ->with(['items.product', 'items.variant', 'items.tebexPackage'])
            ->where('status', self::OPEN)
            ->where(function ($query) use ($userId, $sessionId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                }

                $query->orWhere('session_id', $sessionId);
            })
            ->latest('id')
            ->first();

        if (! $cart && $create) {
            $cart = Cart::create([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'status' => self::OPEN,
                'currency' => strtoupper((string) config('commerce.currency', 'USD')),
            ]);
        }

        if ($cart && $userId && ! $cart->user_id) {
            $cart->user_id = $userId;
            $cart->save();
        }

        return $cart?->loadMissing(['items.product', 'items.variant', 'items.tebexPackage']);
    }

    public static function addItem(
        Cart $cart,
        Product $product,
        ?ProductVariant $variant,
        Price $price,
        int $quantity,
    ): CartItem {
        $max = (int) config('commerce.checkout.max_quantity', 20);

        $cartItem = $cart->items()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->first();

        if ($cartItem) {
            $cartItem->quantity = min($max, $cartItem->quantity + $quantity);
        } else {
            $cartItem = $cart->items()->make([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => min($max, $quantity),
            ]);
        }

        $cartItem->unit_price = $price->amount;
        $cartItem->total = self::lineTotal($price->amount, $cartItem->quantity, $price->currency);
        $cartItem->snapshot = [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
            ],
            'variant' => $variant ? [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
            ] : null,
        ];

        $cartItem->save();

        self::recalculate($cart);

        return $cartItem;
    }

    /**
     * Add a Tebex package at the price Tebex quoted for it (before tax).
     *
     * A package that cannot be bought more than once, or that a customer may only have so many of, is held to that.
     * All the packages in a cart are in one currency, since an order is.
     *
     * @throws CheckoutException When it cannot go in this cart.
     */
    public static function addPackage(Cart $cart, TebexPackage $package, int $quantity): CartItem
    {
        $cart->loadMissing('items');

        if (self::kind($cart) === self::PRODUCTS) {
            throw new CheckoutException('Your cart has products in it, and packages are bought separately. Check out or empty your cart first.');
        }

        if ($cart->items->isNotEmpty() && strtoupper($cart->currency) !== strtoupper($package->currency)) {
            throw new CheckoutException("This package is sold in {$package->currency}, and your cart is in {$cart->currency}. Check out or empty your cart first.");
        }

        $max = self::maxQuantityFor($package);
        $cartItem = $cart->items->first(fn (CartItem $item) => $item->tebex_package_id === $package->id);

        if ($cartItem) {
            $cartItem->quantity = min($max, $cartItem->quantity + $quantity);
        } else {
            $cartItem = $cart->items()->make([
                'tebex_package_id' => $package->id,
                'quantity' => min($max, $quantity),
            ]);
        }

        $cartItem->unit_price = $package->base_price;
        $cartItem->total = self::lineTotal($package->base_price, $cartItem->quantity, $package->currency);
        $cartItem->snapshot = [
            'package' => [
                'id' => $package->id,
                'tebex_id' => $package->tebex_id,
                'name' => $package->name,
                'ident' => $package->ident,
            ],
        ];

        $cartItem->save();

        self::recalculate($cart);

        return $cartItem;
    }

    /**
     * The most of one package a cart may hold: the shop's limit, or one for a package that is not sold in quantities,
     * or the number a customer is allowed.
     */
    public static function maxQuantityFor(TebexPackage $package): int
    {
        $max = (int) config('commerce.checkout.max_quantity', 20);

        if ($package->disable_quantity) {
            return 1;
        }

        return $package->user_limit > 0 ? min($max, $package->user_limit) : $max;
    }

    public static function updateQuantity(CartItem $item, int $quantity): CartItem
    {
        $cart = $item->cart;
        $item->quantity = $quantity;
        $item->total = self::lineTotal($item->unit_price, $quantity, $cart->currency);
        $item->save();

        self::recalculate($cart);

        return $item;
    }

    public static function removeItem(CartItem $item): void
    {
        $cart = $item->cart;
        $item->delete();

        self::recalculate($cart);
    }

    /**
     * Refresh the stored subtotal from the lines. The cart is only a
     * convenience view of prices; checkout prices everything again.
     */
    public static function recalculate(Cart $cart): void
    {
        $cart->load('items.tebexPackage');

        // A cart of packages is in their currency; any other is in the shop's.
        $cart->currency = self::kind($cart) === self::PACKAGES
            ? strtoupper((string) $cart->items->first()?->tebexPackage?->currency)
            : strtoupper((string) config('commerce.currency', 'USD'));

        $subtotal = $cart->items->reduce(
            fn (Money $carry, CartItem $item) => $carry->add(Money::parse($item->total, $cart->currency)),
            Money::zero($cart->currency),
        );

        $cart->subtotal = $subtotal->toDecimal();
        $cart->save();
    }

    /**
     * @return array{id: int, currency: string, subtotal: string, count: int, items: list<array<string, mixed>>}|null
     */
    public static function summary(?Cart $cart): ?array
    {
        if (! $cart) {
            return null;
        }

        $cart->loadMissing(['items.product', 'items.variant', 'items.tebexPackage']);

        // One query for every line's main picture.
        $pictures = ProductImage::query()
            ->whereIn('product_id', $cart->items->pluck('product_id')->filter()->unique()->all())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->unique('product_id')
            ->keyBy('product_id');

        return [
            'id' => $cart->id,
            'currency' => $cart->currency,
            'subtotal' => (string) $cart->subtotal,
            'count' => (int) $cart->items->sum('quantity'),
            'items' => $cart->items
                ->map(fn (CartItem $item) => [
                    'id' => $item->id,
                    'name' => $item->isTebexPackage()
                        ? ($item->tebexPackage->name ?? $item->snapshot['package']['name'] ?? 'Package')
                        : ($item->product?->name ?? $item->snapshot['product']['name'] ?? 'Product'),
                    'slug' => $item->product?->slug,
                    // Where the line links to: its product page, or the page of its package.
                    'href' => match (true) {
                        $item->isTebexPackage() => $item->tebexPackage !== null && $item->tebexPackage->removed_at === null
                            ? route('shop.packages.show', $item->tebexPackage->tebex_id)
                            : null,
                        $item->product?->slug !== null => route('shop.products.show', $item->product->slug),
                        default => null,
                    },
                    'image' => match (true) {
                        $item->isTebexPackage() => $item->tebexPackage?->image_url,
                        $item->product_id !== null => $pictures->get($item->product_id)?->thumbUrl(),
                        default => null,
                    },
                    'variant' => $item->variant?->name ?? $item->snapshot['variant']['name'] ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'total' => (string) $item->total,
                ])
                ->values()
                ->all(),
        ];
    }

    private static function lineTotal(string|float|int $unitPrice, int $quantity, string $currency): string
    {
        return Money::parse($unitPrice, $currency)->multiply($quantity)->toDecimal();
    }
}
