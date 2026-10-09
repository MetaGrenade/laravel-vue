<?php

namespace App\Models;

use App\Support\Commerce\Catalogue\ProductImages;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A picture of a product, kept at three sizes. Created and removed only by
 * {@see ProductImages}, which also deletes the files.
 */
class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'disk',
        'path',
        'medium_path',
        'thumb_path',
        'width',
        'height',
        'bytes',
        'alt',
        'position',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function mediumUrl(): string
    {
        return Storage::disk($this->disk)->url($this->medium_path);
    }

    public function thumbUrl(): string
    {
        return Storage::disk($this->disk)->url($this->thumb_path);
    }

    /**
     * Every file this image is made of.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return [$this->path, $this->medium_path, $this->thumb_path];
    }

    /**
     * What the storefront needs to show it.
     *
     * @return array{url: string, medium: string, thumb: string, alt: string|null, width: int, height: int}
     */
    public function toStorefront(string $fallbackAlt = ''): array
    {
        return [
            'url' => $this->url(),
            'medium' => $this->mediumUrl(),
            'thumb' => $this->thumbUrl(),
            'alt' => $this->alt ?: $fallbackAlt,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
