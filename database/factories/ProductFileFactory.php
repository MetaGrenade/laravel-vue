<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Makes the row only. Tests that download a file put real bytes on the (fake) disk with
 * {@see self::withContents()}.
 *
 * @extends Factory<ProductFile>
 */
class ProductFileFactory extends Factory
{
    protected $model = ProductFile::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => 'Manual',
            'original_name' => 'manual.pdf',
            'disk' => 'local',
            'path' => 'product-files/'.strtolower((string) Str::ulid()),
            'size' => 1024,
            'mime' => 'application/pdf',
            'sha256' => str_repeat('a', 64),
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Write these bytes to the file's path on its disk and record their real size and checksum.
     */
    public function withContents(string $contents): static
    {
        return $this->afterMaking(function (ProductFile $file) use ($contents) {
            Storage::disk($file->disk)->put($file->path, $contents);
            $file->size = strlen($contents);
            $file->sha256 = hash('sha256', $contents);
        });
    }
}
