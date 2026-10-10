<?php

namespace App\Support\Commerce\Digital;

use App\Models\Product;
use App\Models\ProductFile;
use App\Support\Commerce\Catalogue\CatalogueException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The files a product delivers: keeps the rows and the stored files in step.
 *
 * Uploads go to a private disk under a generated name with no extension, so nothing the uploader
 * typed decides where a file is kept or how it could be run if the disk were ever served. The name
 * the buyer sees (and saves the file as) is a separate, editable label. A file is never read back to
 * the browser except by the download route.
 */
class ProductFiles
{
    public function disk(): string
    {
        return (string) config('commerce.downloads.disk', 'local');
    }

    /**
     * @throws CatalogueException When the product has as many files as it may.
     */
    public function add(Product $product, UploadedFile $upload, ?string $name = null): ProductFile
    {
        $stored = $this->store($product, $upload);

        try {
            return DB::transaction(function () use ($product, $stored, $name) {
                // The count and the position are read under a lock, so two uploads at once cannot
                // both take the last place or the same position.
                Product::query()->whereKey($product->id)->lockForUpdate()->first();

                $max = (int) config('commerce.downloads.max_per_product', 20);

                if (ProductFile::query()->where('product_id', $product->id)->count() >= $max) {
                    throw new CatalogueException("A product can have at most {$max} files. Delete one before adding another.");
                }

                return ProductFile::create([
                    'product_id' => $product->id,
                    'name' => $this->label($name, $stored['original_name']),
                    ...$stored,
                    'position' => (int) ProductFile::query()->where('product_id', $product->id)->max('position') + 1,
                    'is_active' => true,
                ]);
            });
        } catch (Throwable $exception) {
            // Nothing was recorded, so the file just stored is not wanted.
            Storage::disk($stored['disk'])->delete($stored['path']);

            throw $exception;
        }
    }

    /**
     * Put a new file in place of the current one, keeping the label and every buyer's access. The
     * old file is removed once the new one is recorded.
     */
    public function replace(ProductFile $file, UploadedFile $upload): ProductFile
    {
        $product = Product::query()->findOrFail($file->product_id);
        $stored = $this->store($product, $upload);
        $old = ['disk' => $file->disk, 'path' => $file->path];

        try {
            $file->update($stored);
        } catch (Throwable $exception) {
            Storage::disk($stored['disk'])->delete($stored['path']);

            throw $exception;
        }

        Storage::disk($old['disk'])->delete($old['path']);

        return $file->refresh();
    }

    public function update(ProductFile $file, string $name, bool $active): ProductFile
    {
        $file->update(['name' => $this->label($name, $file->original_name), 'is_active' => $active]);

        return $file;
    }

    public function delete(ProductFile $file): void
    {
        $disk = $file->disk;
        $path = $file->path;

        $file->delete();

        Storage::disk($disk)->delete($path);
    }

    /**
     * Where a product's files are, so they can be removed after the product is.
     *
     * @return array<string, list<string>> Paths by disk.
     */
    public function filesOf(Product $product): array
    {
        $files = [];

        foreach (ProductFile::query()->where('product_id', $product->id)->get(['disk', 'path']) as $file) {
            $files[$file->disk] = [...($files[$file->disk] ?? []), $file->path];
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

    /**
     * Write the upload to the private disk and describe it.
     *
     * @return array{disk: string, path: string, original_name: string, size: int, mime: string|null, sha256: string}
     *
     * @throws CatalogueException When it cannot be saved.
     */
    private function store(Product $product, UploadedFile $upload): array
    {
        $disk = $this->disk();

        // Files here are only for people who have paid. A disk the web serves would hand them to anyone
        // who learns the path, so it is refused outright rather than trusted to be configured right.
        if (config("filesystems.disks.{$disk}.visibility") === 'public') {
            throw new CatalogueException("The download disk \"{$disk}\" is public, so anyone could fetch these files. Set COMMERCE_DOWNLOAD_DISK to a private disk.");
        }

        $path = "product-files/{$product->id}/".strtolower((string) Str::ulid());

        $source = $upload->getRealPath();
        $stream = $source === false ? false : fopen($source, 'rb');

        if ($stream === false) {
            throw new CatalogueException('That upload could not be read. Try again.');
        }

        try {
            $saved = Storage::disk($disk)->writeStream($path, $stream);
            $hash = hash_file('sha256', (string) $source);
        } finally {
            fclose($stream);
        }

        if (! $saved || $hash === false) {
            throw new CatalogueException('The file could not be saved. Check that the storage disk is writable and has room.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => $this->cleanName($upload->getClientOriginalName()),
            'size' => (int) $upload->getSize(),
            // What the server sees in the file, not what the browser claimed.
            'mime' => $upload->getMimeType(),
            'sha256' => $hash,
        ];
    }

    /**
     * A name safe to show and to save a file as: no directories, no control characters.
     */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return $name === '' ? 'download' : mb_substr($name, 0, 255);
    }

    private function label(?string $name, string $fallback): string
    {
        $name = trim((string) $name);

        return mb_substr($name === '' ? $fallback : $name, 0, 255);
    }
}
