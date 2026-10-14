<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A file a product delivers once it is paid for. It is kept on a private disk and only ever read
 * by the download route.
 */
class ProductFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'original_name',
        'disk',
        'path',
        'size',
        'mime',
        'sha256',
        'is_active',
        'position',
    ];

    protected $casts = [
        'size' => 'integer',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<DownloadCount, $this>
     */
    public function counts(): HasMany
    {
        return $this->hasMany(DownloadCount::class);
    }
}
