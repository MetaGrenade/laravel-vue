<?php

namespace App\Support\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class CartManager
{
    public const OPEN = 'open';

    public const CONVERTED = 'converted';

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
            ->with(['items.product', 'items.variant'])
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

        return $cart?->loadMissing(['items.product', 'items.variant']);
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
        $cart->load('items');

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

        $cart->loadMissing(['items.product', 'items.variant']);

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
                    'name' => $item->product?->name ?? $item->snapshot['product']['name'] ?? 'Product',
                    'slug' => $item->product?->slug,
                    'image' => $item->product_id !== null ? $pictures->get($item->product_id)?->thumbUrl() : null,
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
