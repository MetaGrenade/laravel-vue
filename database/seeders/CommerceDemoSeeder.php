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
        $product = Product::create([
            'name' => 'Demo Hoodie',
            'slug' => 'demo-hoodie',
            'description' => 'Soft mid-weight hoodie ready for checkout wiring.',
            'metadata' => [
                'hero_image' => '/images/demo-hoodie.png',
            ],
        ]);

        $sizeOption = ProductOption::create([
            'product_id' => $product->id,
            'name' => 'size',
            'display_name' => 'Size',
            'position' => 1,
        ]);

        $sizes = collect(['S', 'M', 'L', 'XL'])->map(function (string $label, int $index) use ($sizeOption) {
            return ProductOptionValue::create([
                'product_option_id' => $sizeOption->id,
                'value' => $label,
                'position' => $index,
            ]);
        });

        $variants = $sizes->map(function (ProductOptionValue $value, int $index) use ($product) {
            return ProductVariant::create([
                'product_id' => $product->id,
                'name' => 'Hoodie ' . $value->value,
                'sku' => 'HOODIE-' . Str::upper($value->value),
                'option_values' => ['size' => $value->value],
                'is_default' => $index === 1,
            ]);
        });

        $variants->each(function (ProductVariant $variant) {
            Price::create([
                'priceable_type' => ProductVariant::class,
                'priceable_id' => $variant->id,
                'currency' => 'USD',
                'amount' => 69.00,
                'compare_at_amount' => 79.00,
            ]);

            InventoryItem::create([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity' => 25,
                'allow_backorder' => false,
            ]);
        });

        $cart = Cart::create([
            'status' => 'open',
            'currency' => 'USD',
            'subtotal' => 138.00,
            'metadata' => ['note' => 'Seeded cart ready for UI wiring'],
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variants->first()->id,
            'quantity' => 2,
            'unit_price' => 69.00,
            'total' => 138.00,
            'snapshot' => [
                'size' => $variants->first()->option_values['size'] ?? 'M',
            ],
        ]);

        $order = Order::create([
            'cart_id' => $cart->id,
            'status' => 'processing',
            'currency' => 'USD',
            'subtotal' => 138.00,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 138.00,
            'notes' => 'Example order for UI scaffolding',
            'metadata' => ['channel' => 'seed'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variants->first()->id,
            'quantity' => 2,
            'unit_price' => 69.00,
            'subtotal' => 138.00,
            'tax_total' => 0,
            'discount_total' => 0,
            'description' => 'Hoodie bundle',
            'metadata' => ['size' => $variants->first()->option_values['size'] ?? 'M'],
        ]);
    }
}
