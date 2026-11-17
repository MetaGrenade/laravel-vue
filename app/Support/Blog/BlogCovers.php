<?php

namespace App\Support\Blog;

use App\Models\Blog;
use App\Models\BlogRevision;
use App\Support\Commerce\Catalogue\ImageProcessor;
use App\Support\Commerce\Catalogue\ImageRejected;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A blog cover is a public picture, so it is decoded and saved again as WebP before it is stored.
 * The upload's own bytes, name and metadata are not kept.
 *
 * It is kept at three widths, because a cover is shown in a card a third of the page wide on one page and
 * across the whole page on another (docs/images.md). The row points at the largest, whose name carries its
 * size in pixels (`{id}-1600x400-large.webp`), and the other two are named after the id (`{id}-medium.webp`,
 * `{id}-small.webp`). Nothing but the name says that a cover has them, or how big it is: a cover stored
 * before this has one file, and a row that points at it still works (it is drawn at its one size).
 */
class BlogCovers
{
    /** The name of the largest file: after the id come its width and height in pixels. */
    private const LARGE = '#^(?<base>.+)-(?<width>\d+)x(?<height>\d+)-large\.webp$#';

    /** The other sizes, smallest first, by the name they carry and the width they are made at. */
    public const WIDTHS = ['small' => 480, 'medium' => 960];

    /** Where covers are kept, on the public disk. A cover is a file directly in it. */
    public const DIRECTORY = 'blog-covers';

    /** A file that no post names is left alone until it is this old: a cover is written before the row that names it. */
    public const STRAY_MINUTES = 60;

    public function __construct(private ImageProcessor $images) {}

    /**
     * What a path that is a cover looks like: a file directly in the covers directory, named by letters, digits, dots, dashes and
     * underscores, and not starting with a dot. It is the shape of every name this class makes and of the ones the first version made
     * (`blog-covers/{40 random characters}.jpg`).
     *
     * The paths are kept in the rows and nothing else says they are covers, so whatever deletes a file because a row names it asks
     * this of the stored value: a row that names another file of the public disk (a product picture) does not make it the cover's.
     */
    public static function pathPattern(): string
    {
        return '#^'.preg_quote(self::DIRECTORY, '#').'/[A-Za-z0-9][A-Za-z0-9._-]*$#D';
    }

    public function isCoverPath(?string $path): bool
    {
        return is_string($path) && preg_match(self::pathPattern(), $path) === 1;
    }

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
        return $this->parse($path) !== null;
    }

    /**
     * Remove a cover: every file it has. Nothing happens for no cover, or for a path that is not a cover's (see {@see self::pathPattern()}).
     */
    public function delete(?string $path): void
    {
        if ($this->isCoverPath($path)) {
            Storage::disk('public')->delete($this->files((string) $path));
        }
    }

    /**
     * Remove covers a post was deleted with, and say so if a disk cannot be reached rather than undo a deletion that has happened.
     *
     * @param  list<string>  $paths  From {@see self::pathsOfPosts()}.
     */
    public function forget(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                $this->delete($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * The covers of some posts, and of their revisions, as the paths the rows keep. Ask BEFORE the posts are deleted: their revisions go
     * with them (a cascade, which runs no model event), and the rows are the only thing that says which files were theirs.
     *
     * A path is left out when it is not a cover's, and when a post that is not one of these, or a revision of one, names it as well.
     *
     * @param  EloquentBuilder<Blog>|QueryBuilder  $posts  A query that selects the `id` of the posts.
     * @return list<string>
     */
    public function pathsOfPosts(EloquentBuilder|QueryBuilder $posts): array
    {
        $paths = Blog::query()->toBase()->whereIn('id', $posts)->whereNotNull('cover_image')->distinct()->pluck('cover_image')
            ->merge(BlogRevision::query()->toBase()->whereIn('blog_id', $posts)->whereNotNull('cover_image')->distinct()->pluck('cover_image'))
            ->filter(fn ($path) => $this->isCoverPath($path))
            ->unique()
            ->values();

        $shared = [];

        foreach ($paths->chunk(500) as $chunk) {
            $names = $chunk->values()->all();

            foreach (Blog::query()->toBase()->whereIn('cover_image', $names)->whereNotIn('id', $posts)->distinct()->pluck('cover_image') as $path) {
                $shared[$path] = true;
            }

            foreach (BlogRevision::query()->toBase()->whereIn('cover_image', $names)->whereNotIn('blog_id', $posts)->distinct()->pluck('cover_image') as $path) {
                $shared[$path] = true;
            }
        }

        return $paths->reject(fn ($path) => isset($shared[$path]))->values()->all();
    }

    /**
     * Every file a cover is kept in, for the ones that carry the files of somebody else's deletion (a member's posts).
     *
     * @return list<string>
     */
    public function filesOf(string $path): array
    {
        return $this->isCoverPath($path) ? $this->files($path) : [];
    }

    /**
     * Files in the covers directory that no post and no revision names, and that are old enough not to be one that is being saved.
     * A cover that was never deleted with its post, or whose save failed after the file was written, leaves them.
     *
     * @return list<string>
     */
    public function strays(): array
    {
        $disk = Storage::disk('public');
        $named = [];

        foreach ($this->named() as $path) {
            foreach ($this->files($path) as $file) {
                $named[$file] = true;
            }
        }

        $limit = now()->subMinutes(self::STRAY_MINUTES)->getTimestamp();
        $strays = [];

        foreach ($disk->files(self::DIRECTORY) as $file) {
            // `.gitignore` and anything else that is not named like a cover is not one, whoever put it there.
            if (isset($named[$file]) || ! $this->isCoverPath($file)) {
                continue;
            }

            if ($disk->lastModified($file) <= $limit) {
                $strays[] = $file;
            }
        }

        return $strays;
    }

    /**
     * @return list<string> Every cover path a post or a revision keeps.
     */
    private function named(): array
    {
        return Blog::query()->toBase()->whereNotNull('cover_image')->distinct()->pluck('cover_image')
            ->merge(BlogRevision::query()->toBase()->whereNotNull('cover_image')->distinct()->pluck('cover_image'))
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Width over height of the cover, or null for one that has a single file and no recorded size. A page needs it
     * to choose a size: a wide cover in a short box is scaled up to the box's height and cropped at the sides, so it
     * fills more pixels across than the box is wide (docs/images.md).
     */
    public function aspect(?string $path): ?float
    {
        $parsed = is_string($path) ? $this->parse($path) : null;

        return $parsed === null ? null : round($parsed['width'] / $parsed['height'], 4);
    }

    /**
     * The `srcset` of a cover: each file with its width, for the browser to choose from. Null for a cover
     * that has one file, which is then shown as it is.
     *
     * The widths are what the files are: the largest is as wide as the cover was cut to, and a cover narrower
     * than a size has it at its own width. Two files of one width are the same picture, so only one is listed.
     */
    public function srcset(?string $path): ?string
    {
        $parsed = is_string($path) ? $this->parse($path) : null;

        if ($parsed === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $sources = [];

        foreach (self::WIDTHS as $size => $width) {
            $sources[min($width, $parsed['width'])] ??= $disk->url($this->sibling($parsed['base'], $size));
        }

        $sources[$parsed['width']] ??= $disk->url($path);

        ksort($sources);

        return implode(', ', array_map(fn (int $width, string $url) => $url.' '.$width.'w', array_keys($sources), $sources));
    }

    /**
     * @return array{base: string, width: int, height: int}|null
     */
    private function parse(string $path): ?array
    {
        if (preg_match(self::LARGE, $path, $found) !== 1 || (int) $found['height'] < 1) {
            return null;
        }

        return ['base' => $found['base'], 'width' => (int) $found['width'], 'height' => (int) $found['height']];
    }

    /**
     * @return list<string> Every file a cover is kept in.
     */
    private function files(string $path): array
    {
        $parsed = $this->parse($path);

        if ($parsed === null) {
            return [$path];
        }

        return [$path, ...array_map(fn (string $size) => $this->sibling($parsed['base'], $size), array_keys(self::WIDTHS))];
    }

    private function sibling(string $base, string $size): string
    {
        return $base.'-'.$size.'.webp';
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

        $base = 'blog-covers/'.strtolower((string) Str::ulid());
        $path = "{$base}-{$encoded['width']}x{$encoded['height']}-large.webp";
        $files = [$path => $encoded['large']];

        foreach (self::WIDTHS as $size => $width) {
            $files[$this->sibling($base, $size)] = $encoded['widths'][$width];
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
