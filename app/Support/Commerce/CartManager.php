<?php

namespace App\Support\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class CartManager
{
    public static function forRequest(Request $request, bool $create = false): ?Cart
    {
        $sessionId = $request->session()->getId();
        $userId = $request->user()?->id;

        $cart = Cart::query()
            ->with(['items.product', 'items.variant'])
            ->where(function ($query) use ($userId, $sessionId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                }

                $query->orWhere('session_id', $sessionId);
            })
            ->latest()
            ->first();

        if (!$cart && $create) {
            $cart = Cart::create([
                'user_id' => $userId,
                'session_id' => $sessionId,
            ]);
        }

        if ($cart && $userId && !$cart->user_id) {
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
        $cartItem = $cart->items()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->first();

        if ($cartItem) {
            $cartItem->quantity += $quantity;
            $cartItem->unit_price = $price->amount;
            $cartItem->total = $cartItem->quantity * $cartItem->unit_price;
        } else {
            $cartItem = $cart->items()->make([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'unit_price' => $price->amount,
                'total' => $price->amount * $quantity,
            ]);
        }

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

        $cart->currency = $price->currency ?? $cart->currency;
        $cart->subtotal = $cart->items()->sum('total');
        $cart->save();

        return $cartItem;
    }

    public static function summary(?Cart $cart): ?array
    {
        if (!$cart) {
            return null;
        }

        return [
            'id' => $cart->id,
            'currency' => $cart->currency,
            'subtotal' => (string) $cart->subtotal,
            'items' => $cart->items
                ->map(fn (CartItem $item) => [
                    'id' => $item->id,
                    'name' => $item->product?->name ?? $item->snapshot['product']['name'] ?? 'Product',
                    'variant' => $item->variant?->name ?? $item->snapshot['variant']['name'] ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'total' => (string) $item->total,
                ])
                ->values()
                ->all(),
        ];
    }
}
