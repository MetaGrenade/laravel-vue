<?php

namespace App\Support\Commerce\Catalogue;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A product's pictures: adding, ordering and removing them, and keeping the files and the
 * rows in step so that neither is left behind without the other.
 */
class ProductImages
{
    public function __construct(private readonly ImageProcessor $processor) {}

    /**
     * Process an upload and add it as the product's last picture.
     *
     * @throws ImageRejected When the file is not a picture the shop will keep.
     * @throws CatalogueException When the product already has as many pictures as it may.
     */
    public function add(Product $product, UploadedFile $file, ?string $alt = null): ProductImage
    {
        $this->assertRoom($product);

        $processed = $this->processor->process((string) $file->get());

        $disk = (string) config('commerce.images.disk', 'public');
        $name = strtolower((string) Str::ulid());
        $paths = [
            'path' => "products/{$product->id}/{$name}-large.webp",
            'medium_path' => "products/{$product->id}/{$name}-medium.webp",
            'thumb_path' => "products/{$product->id}/{$name}-thumb.webp",
        ];

        $storage = Storage::disk($disk);

        try {
            foreach ([['path', $processed->large], ['medium_path', $processed->medium], ['thumb_path', $processed->thumb]] as [$column, $data]) {
                if (! $storage->put($paths[$column], $data, 'public')) {
                    throw new CatalogueException('The picture could not be saved. Check that the storage disk is writable.');
                }
            }

            return DB::transaction(function () use ($product, $paths, $disk, $processed, $alt) {
                // The count and the position are read under a lock, so two uploads at once cannot
                // both take the last place or the same position.
                Product::query()->whereKey($product->id)->lockForUpdate()->first();
                $this->assertRoom($product);

                return $product->images()->create([
                    'disk' => $disk,
                    ...$paths,
                    'width' => $processed->width,
                    'height' => $processed->height,
                    'bytes' => strlen($processed->large),
                    'alt' => $this->cleanAlt($alt),
                    'position' => (int) ProductImage::query()->where('product_id', $product->id)->max('position') + 1,
                ]);
            });
        } catch (Throwable $exception) {
            // Whatever went wrong, do not leave files that no row points at.
            $storage->delete(array_values($paths));

            throw $exception;
        }
    }

    public function updateAlt(ProductImage $image, ?string $alt): ProductImage
    {
        $image->update(['alt' => $this->cleanAlt($alt)]);

        return $image;
    }

    /**
     * Put the pictures in the given order. The list must name every picture of the product,
     * once each, so an order can never lose or invent one.
     *
     * @param  list<int>  $ids
     *
     * @throws CatalogueException
     */
    public function reorder(Product $product, array $ids): void
    {
        DB::transaction(function () use ($product, $ids) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $existing = ProductImage::query()->where('product_id', $product->id)->pluck('id')->all();

            if (count($ids) !== count($existing) || array_diff($ids, $existing) !== []) {
                throw new CatalogueException('The pictures changed while you were arranging them. Reload the page and try again.');
            }

            foreach ($ids as $position => $id) {
                ProductImage::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /**
     * Make a picture the main one, keeping the others in their order.
     */
    public function makeMain(ProductImage $image): void
    {
        $others = ProductImage::query()
            ->where('product_id', $image->product_id)
            ->whereKeyNot($image->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->reorder($image->product()->firstOrFail(), [$image->id, ...$others]);
    }

    /**
     * Delete a picture and its files, and close the gap it leaves.
     */
    public function delete(ProductImage $image): void
    {
        $paths = $image->paths();
        $disk = $image->disk;

        DB::transaction(function () use ($image) {
            Product::query()->whereKey($image->product_id)->lockForUpdate()->first();
            $image->delete();

            $remaining = ProductImage::query()->where('product_id', $image->product_id)->orderBy('position')->orderBy('id')->pluck('id');

            foreach ($remaining as $position => $id) {
                ProductImage::query()->whereKey($id)->update(['position' => $position]);
            }
        });

        // After the row is gone, so a failure here leaves at worst a stray file, never a row whose
        // file is missing.
        Storage::disk($disk)->delete($paths);
    }

    /**
     * The files of every picture a product has, as disk => paths, for deleting once the product is.
     *
     * @return array<string, list<string>>
     */
    public function filesOf(Product $product): array
    {
        $files = [];

        foreach (ProductImage::query()->where('product_id', $product->id)->get() as $image) {
            $files[$image->disk] = [...($files[$image->disk] ?? []), ...$image->paths()];
        }

        return $files;
    }

    /**
     * @param  array<string, list<string>>  $files  As returned by {@see self::filesOf()}.
     */
    public function deleteFiles(array $files): void
    {
        foreach ($files as $disk => $paths) {
            Storage::disk($disk)->delete($paths);
        }
    }

    private function assertRoom(Product $product): void
    {
        $max = (int) config('commerce.images.max_per_product', 12);

        if ($product->images()->count() >= $max) {
            throw new CatalogueException("A product can have at most {$max} pictures. Delete one before adding another.");
        }
    }

    private function cleanAlt(?string $alt): ?string
    {
        $alt = $alt === null ? null : trim($alt);

        return $alt === null || $alt === '' ? null : mb_substr($alt, 0, 255);
    }
}
