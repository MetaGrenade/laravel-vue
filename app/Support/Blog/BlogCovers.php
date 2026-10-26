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
 */
class BlogCovers
{
    public function __construct(private ImageProcessor $images) {}

    /**
     * @throws ImageRejected
     */
    public function store(UploadedFile $upload): string
    {
        $longest = (int) config('commerce.images.sizes.large', 1600);
        $bytes = $this->images->webp($upload->getContent(), $longest);
        $path = 'blog-covers/'.Str::ulid().'.webp';

        if (! Storage::disk('public')->put($path, $bytes, 'public')) {
            throw new ImageRejected('The cover could not be saved. Try again.');
        }

        return $path;
    }
}
