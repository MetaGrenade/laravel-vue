<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CommerceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $products = collect([
            [
                'name' => 'Demo Hoodie',
                'slug' => 'demo-hoodie',
                'description' => 'Soft mid-weight hoodie ready for checkout wiring.',
                'metadata' => ['hero_image' => '/images/demo-hoodie.png'],
                'options' => [
                    [
                        'name' => 'size',
                        'display_name' => 'Size',
                        'values' => ['S', 'M', 'L', 'XL'],
                    ],
                ],
                'base_price' => 69.00,
                'compare_at' => 79.00,
                'inventory' => 25,
            ],
            [
                'name' => 'Demo T-Shirt',
                'slug' => 'demo-tee',
                'description' => 'Lightweight tee available in bold colors.',
                'metadata' => ['hero_image' => '/images/demo-tee.png'],
                'options' => [
                    [
                        'name' => 'color',
                        'display_name' => 'Color',
                        'values' => ['Black', 'White', 'Blue'],
                    ],
                ],
                'base_price' => 32.00,
                'compare_at' => 42.00,
                'inventory' => 40,
            ],
            [
                'name' => 'Demo Mug',
                'slug' => 'demo-mug',
                'description' => 'Everyday mug with a glossy finish.',
                'metadata' => ['hero_image' => '/images/demo-mug.png'],
                'options' => [],
                'base_price' => 18.00,
                'compare_at' => 24.00,
                'inventory' => 50,
            ],
        ]);

        $variants = $products->flatMap(function (array $productData) {
            $product = Product::updateOrCreate(
                ['slug' => $productData['slug']],
                [
                    'name' => $productData['name'],
                    'description' => $productData['description'],
                    'metadata' => $productData['metadata'],
                ],
            );

            $optionValues = collect($productData['options'])->map(function (array $option, int $optionIndex) use ($product) {
                $productOption = ProductOption::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'name' => $option['name'],
                    ],
                    [
                        'display_name' => $option['display_name'],
                        'position' => $optionIndex + 1,
                    ],
                );

                return [
                    'name' => $productOption->name,
                    'values' => collect($option['values'])->map(function (string $value, int $valueIndex) use ($productOption) {
                        return ProductOptionValue::updateOrCreate(
                            [
                                'product_option_id' => $productOption->id,
                                'value' => $value,
                            ],
                            [
                                'position' => $valueIndex,
                            ],
                        );
                    }),
                ];
            });

            $combinations = collect([[]]);
            $optionValues->each(function (array $option) use (&$combinations) {
                $combinations = $combinations->flatMap(function (array $combination) use ($option) {
                    return $option['values']->map(function (ProductOptionValue $value) use ($combination, $option) {
                        return array_merge($combination, [$option['name'] => $value->value]);
                    });
                });
            });

            if ($combinations->isEmpty()) {
                $combinations = collect([[]]);
            }

            return $combinations->values()->map(function (array $combination, int $index) use ($product, $productData) {
                $name = $productData['options']
                    ? $product->name . ' ' . implode(' / ', $combination)
                    : $product->name;

                $skuParts = [Str::upper(Str::slug($product->slug))];
                foreach ($combination as $value) {
                    $skuParts[] = Str::upper(Str::slug($value));
                }

                $variant = ProductVariant::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'sku' => implode('-', $skuParts),
                    ],
                    [
                        'name' => $name,
                        'option_values' => $combination,
                        'is_default' => $index === 0,
                    ],
                );

                Price::updateOrCreate(
                    [
                        'priceable_type' => ProductVariant::class,
                        'priceable_id' => $variant->id,
                        'currency' => 'USD',
                    ],
                    [
                        'amount' => $productData['base_price'],
                        'compare_at_amount' => $productData['compare_at'],
                    ],
                );

                InventoryItem::updateOrCreate(
                    [
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                    ],
                    [
                        'quantity' => $productData['inventory'],
                        'allow_backorder' => false,
                    ],
                );

                return $variant;
            });
        });

        $seedKey = 'commerce-demo';

        $cart = Cart::where('metadata->seed_key', $seedKey)->first();
        if (! $cart) {
            $cart = Cart::create([
                'status' => 'open',
                'currency' => 'USD',
                'subtotal' => 0,
                'metadata' => ['note' => 'Seeded cart ready for UI wiring', 'seed_key' => $seedKey],
            ]);
        }

        $cartItems = $variants->take(2)->map(function (ProductVariant $variant, int $index) use ($cart) {
            $quantity = $index === 0 ? 2 : 1;
            $unitPrice = $variant->prices()->first()?->amount ?? 0;

            $cartItem = CartItem::updateOrCreate(
                [
                    'cart_id' => $cart->id,
                    'product_variant_id' => $variant->id,
                ],
                [
                    'product_id' => $variant->product_id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => $unitPrice * $quantity,
                    'snapshot' => $variant->option_values,
                ],
            );

            return $cartItem;
        });

        $cart->update([
            'subtotal' => $cartItems->sum('total'),
        ]);

        $order = Order::where('metadata->seed_key', $seedKey)->first();
        if (! $order) {
            $order = Order::create([
                'cart_id' => $cart->id,
                'status' => 'processing',
                'currency' => 'USD',
                'subtotal' => $cartItems->sum('total'),
                'tax_total' => 0,
                'shipping_total' => 0,
                'discount_total' => 0,
                'grand_total' => $cartItems->sum('total'),
                'notes' => 'Example order for UI scaffolding',
                'metadata' => ['channel' => 'seed', 'seed_key' => $seedKey],
            ]);
        } else {
            $order->update([
                'cart_id' => $cart->id,
                'subtotal' => $cartItems->sum('total'),
                'grand_total' => $cartItems->sum('total'),
            ]);
        }

        $cartItems->each(function (CartItem $cartItem) use ($order) {
            $price = $cartItem->unit_price;

            OrderItem::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'product_variant_id' => $cartItem->product_variant_id,
                ],
                [
                    'product_id' => $cartItem->product_id,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $price,
                    'subtotal' => $price * $cartItem->quantity,
                    'tax_total' => 0,
                    'discount_total' => 0,
                    'description' => $cartItem->product->name,
                    'metadata' => $cartItem->snapshot,
                ],
            );
        });
    }
}
