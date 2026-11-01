<?php

namespace App\Support\Blog;

use App\Support\Commerce\Catalogue\ImageProcessor;
use App\Support\Commerce\Catalogue\ImageRejected;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A blog cover is a public picture, so it is decoded and saved again as WebP before it is stored.
 * The upload's own bytes, name and metadata are not kept.
 *
 * It is kept at three widths, because a cover is shown in a card a third of the page wide on one page and
 * across the whole page on another (docs/images.md). The row points at the largest, and the other two
 * are named after it, so nothing but the name says that a cover has them: a cover stored before this
 * has one file, and a row that points at it still works (it is drawn at its one size).
 */
class BlogCovers
{
    /** What the largest file's name ends in, and the mark that a cover has the other sizes. */
    private const LARGE = '-large.webp';

    /** The other sizes, smallest first, by the name they carry and the width they are made at. */
    public const WIDTHS = ['small' => 480, 'medium' => 960];

    public function __construct(private ImageProcessor $images) {}

    /**
     * @return string The path to keep on the post: the largest size.
     *
     * @throws ImageRejected
     */
    public function store(UploadedFile $upload): string
    {
        return $this->storeBytes($upload->getContent());
    }

    /**
     * Draw a cover that was stored as one file again at every size, and say where it is now. The file it
     * was is not touched: whoever keeps the row changes it, then deletes the old file ({@see self::delete()}).
     *
     * @return string|null The new path, the path itself when it already has its sizes, or null when there is no file.
     *
     * @throws ImageRejected When the file is not a picture that is allowed.
     */
    public function upgrade(string $path): ?string
    {
        if ($this->hasSizes($path)) {
            return $path;
        }

        $bytes = Storage::disk('public')->get($path);

        return is_string($bytes) && $bytes !== '' ? $this->storeBytes($bytes) : null;
    }

    /** Whether the cover at this path was made with the smaller sizes beside it. */
    public function hasSizes(string $path): bool
    {
        return str_ends_with($path, self::LARGE);
    }

    /**
     * Remove a cover: every file it has. Nothing happens for no cover.
     */
    public function delete(?string $path): void
    {
        if (is_string($path) && $path !== '') {
            Storage::disk('public')->delete($this->files($path));
        }
    }

    /**
     * The `srcset` of a cover: each file with its width, for the browser to choose from. Null for a cover
     * that has one file, which is then shown as it is.
     *
     * The width given for the largest is the longest side it is cut to; a portrait picture is narrower than that, so the
     * browser may think it is a little larger than it is. No size is ever enlarged, so that costs nothing.
     */
    public function srcset(?string $path): ?string
    {
        if (! is_string($path) || ! $this->hasSizes($path)) {
            return null;
        }

        $disk = Storage::disk('public');
        $sources = [];

        foreach (self::WIDTHS as $size => $width) {
            $sources[] = $disk->url($this->sibling($path, $size)).' '.$width.'w';
        }

        $sources[] = $disk->url($path).' '.(int) config('commerce.images.sizes.large', 1600).'w';

        return implode(', ', $sources);
    }

    /**
     * @return list<string> Every file a cover is kept in.
     */
    private function files(string $path): array
    {
        if (! $this->hasSizes($path)) {
            return [$path];
        }

        return [$path, ...array_map(fn (string $size) => $this->sibling($path, $size), array_keys(self::WIDTHS))];
    }

    private function sibling(string $path, string $size): string
    {
        return substr($path, 0, -strlen(self::LARGE)).'-'.$size.'.webp';
    }

    /**
     * @throws ImageRejected
     */
    private function storeBytes(string $bytes): string
    {
        $encoded = $this->images->webpAtWidths(
            $bytes,
            (int) config('commerce.images.sizes.large', 1600),
            array_values(self::WIDTHS),
        );

        $path = 'blog-covers/'.strtolower((string) Str::ulid()).self::LARGE;
        $files = [$path => $encoded['large']];

        foreach (self::WIDTHS as $size => $width) {
            $files[$this->sibling($path, $size)] = $encoded['widths'][$width];
        }

        $disk = Storage::disk('public');

        foreach ($files as $name => $data) {
            if (! $disk->put($name, $data, 'public')) {
                // A cover that is only partly there is worse than none: the row would point at a file
                // whose smaller sizes are missing.
                $disk->delete(array_keys($files));

                throw new ImageRejected('The cover could not be saved. Try again.');
            }
        }

        return $path;
    }
}
