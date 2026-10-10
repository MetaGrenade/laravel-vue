<?php

namespace Database\Seeders;

use App\Enums\CouponType;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductTag;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CommerceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedShippingAndTax();
        $this->seedCoupons();

        $brands = collect([
            ['name' => 'Acme Co.', 'slug' => 'acme', 'description' => 'Default demo brand'],
            ['name' => 'Summit Supply', 'slug' => 'summit-supply', 'description' => 'Outdoor-inspired basics'],
        ])->mapWithKeys(function (array $brand) {
            $brandModel = Brand::updateOrCreate(
                ['slug' => $brand['slug']],
                ['name' => $brand['name'], 'description' => $brand['description']],
            );

            return [$brandModel->name => $brandModel];
        });

        $categories = collect([
            ['name' => 'Apparel', 'slug' => 'apparel', 'description' => 'Hoodies, tees, and wearable goods.'],
            ['name' => 'Accessories', 'slug' => 'accessories', 'description' => 'Everyday add-ons and merch.'],
            ['name' => 'Limited', 'slug' => 'limited', 'description' => 'Short runs and seasonal drops.'],
        ])->mapWithKeys(function (array $category) {
            $categoryModel = ProductCategory::updateOrCreate(
                ['slug' => $category['slug']],
                ['name' => $category['name'], 'description' => $category['description']],
            );

            return [$categoryModel->name => $categoryModel];
        });

        $tags = collect([
            ['name' => 'New', 'slug' => 'new'],
            ['name' => 'Bestseller', 'slug' => 'bestseller'],
            ['name' => 'Eco', 'slug' => 'eco'],
        ])->mapWithKeys(function (array $tag) {
            $tagModel = ProductTag::updateOrCreate(
                ['slug' => $tag['slug']],
                ['name' => $tag['name'], 'description' => null],
            );

            return [$tagModel->name => $tagModel];
        });

        $products = collect([
            [
                'name' => 'Demo Hoodie',
                'slug' => 'demo-hoodie',
                'description' => 'Soft mid-weight hoodie ready for checkout wiring.',
                'metadata' => ['hero_image' => '/images/demo-hoodie.png'],
                'brand' => 'Acme Co.',
                'categories' => ['Apparel', 'Limited'],
                'tags' => ['New'],
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
                'brand' => 'Summit Supply',
                'categories' => ['Apparel'],
                'tags' => ['Bestseller'],
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
                'brand' => 'Acme Co.',
                'categories' => ['Accessories'],
                'tags' => ['Eco'],
                'options' => [],
                'base_price' => 18.00,
                'compare_at' => 24.00,
                'inventory' => 50,
            ],
        ]);

        $variants = $products->flatMap(function (array $productData) use ($categories, $tags) {
            $product = Product::updateOrCreate(
                ['slug' => $productData['slug']],
                [
                    'name' => $productData['name'],
                    'description' => $productData['description'],
                    'metadata' => $productData['metadata'],
                    'brand_id' => $brands[$productData['brand']]->id ?? null,
                ],
            );

            $product->categories()->sync(
                collect($productData['categories'] ?? [])
                    ->map(fn (string $categoryName) => $categories[$categoryName]->id)
                    ->all(),
            );

            $product->tags()->sync(
                collect($productData['tags'] ?? [])
                    ->map(fn (string $tagName) => $tags[$tagName]->id)
                    ->all(),
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
                    ? $product->name.' '.implode(' / ', $combination)
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
                'payment_status' => 'paid',
                'payment_provider' => 'stripe',
                'customer_email' => 'demo.customer@example.com',
                'placed_at' => now(),
                'paid_at' => now(),
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

    /**
     * Example discount codes to try at checkout. Switch them off or delete them in the admin area
     * (a code that has been used on an order can only be switched off).
     */
    private function seedCoupons(): void
    {
        $currency = strtoupper((string) config('commerce.currency', 'USD'));

        $coupons = [
            ['code' => 'WELCOME10', 'description' => 'Demo: 10% off, once per customer', 'type' => CouponType::Percent, 'value' => '10', 'currency' => null, 'minimum_subtotal' => null, 'max_redemptions_per_customer' => 1],
            ['code' => 'SAVE5', 'description' => 'Demo: 5.00 off orders of 25.00 or more', 'type' => CouponType::Fixed, 'value' => '5.00', 'currency' => $currency, 'minimum_subtotal' => '25.00', 'max_redemptions_per_customer' => null],
            ['code' => 'FREESHIP', 'description' => 'Demo: free shipping on orders of 50.00 or more', 'type' => CouponType::FreeShipping, 'value' => null, 'currency' => null, 'minimum_subtotal' => '50.00', 'max_redemptions_per_customer' => null],
        ];

        foreach ($coupons as $coupon) {
            Coupon::updateOrCreate(
                ['code' => $coupon['code']],
                $coupon + ['is_active' => true],
            );
        }
    }

    /**
     * Example shipping zones and tax rates so a fresh install can try the whole
     * checkout. Replace them in the admin area (amounts are in the store currency).
     */
    private function seedShippingAndTax(): void
    {
        $zones = [
            [
                'name' => 'United States',
                'countries' => ['US'],
                'position' => 1,
                'rates' => [
                    ['name' => 'Standard', 'description' => '3–5 business days', 'amount' => '5.00', 'max_subtotal' => '99.99'],
                    ['name' => 'Free standard shipping', 'description' => '3–5 business days, orders over 100', 'amount' => '0.00', 'min_subtotal' => '100.00'],
                    ['name' => 'Express', 'description' => '1–2 business days', 'amount' => '15.00'],
                ],
            ],
            [
                'name' => 'United Kingdom',
                'countries' => ['GB'],
                'position' => 2,
                'rates' => [
                    ['name' => 'Standard', 'description' => '3–6 business days', 'amount' => '8.00'],
                ],
            ],
            [
                'name' => 'Rest of world',
                'countries' => ['*'],
                'position' => 99,
                'rates' => [
                    ['name' => 'International', 'description' => '7–14 business days', 'amount' => '25.00'],
                ],
            ],
        ];

        foreach ($zones as $zoneData) {
            $zone = ShippingZone::updateOrCreate(
                ['name' => $zoneData['name']],
                ['countries' => $zoneData['countries'], 'position' => $zoneData['position'], 'is_active' => true],
            );

            foreach ($zoneData['rates'] as $index => $rate) {
                ShippingRate::updateOrCreate(
                    ['shipping_zone_id' => $zone->id, 'name' => $rate['name']],
                    [
                        'description' => $rate['description'],
                        'amount' => $rate['amount'],
                        'min_subtotal' => $rate['min_subtotal'] ?? null,
                        'max_subtotal' => $rate['max_subtotal'] ?? null,
                        'position' => $index + 1,
                        'is_active' => true,
                    ],
                );
            }
        }

        $taxRates = [
            ['name' => 'VAT', 'country' => 'GB', 'region' => null, 'rate' => '20'],
            ['name' => 'Sales tax', 'country' => 'US', 'region' => 'California', 'rate' => '7.25'],
        ];

        foreach ($taxRates as $taxRate) {
            TaxRate::updateOrCreate(
                ['country' => $taxRate['country'], 'region' => $taxRate['region'], 'name' => $taxRate['name']],
                ['rate' => $taxRate['rate'], 'applies_to_shipping' => true, 'is_active' => true],
            );
        }
    }
}
