<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A product image row with made-up paths: nothing is written to disk, which is enough for
 * everything that only builds links. Upload through the service to get real files.
 *
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    public function definition(): array
    {
        $name = strtolower((string) Str::ulid());

        return [
            'product_id' => Product::factory(),
            'disk' => 'public',
            'path' => "products/test/{$name}-large.webp",
            'medium_path' => "products/test/{$name}-medium.webp",
            'thumb_path' => "products/test/{$name}-thumb.webp",
            'width' => 1200,
            'height' => 800,
            'bytes' => 48_000,
            'alt' => null,
            'position' => 0,
        ];
    }
}
