<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many times one file has been downloaded under one grant.
 */
class DownloadCount extends Model
{
    protected $fillable = [
        'download_grant_id',
        'product_file_id',
        'downloads',
        'last_downloaded_at',
    ];

    protected $casts = [
        'downloads' => 'integer',
        'last_downloaded_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<DownloadGrant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(DownloadGrant::class, 'download_grant_id');
    }

    /**
     * @return BelongsTo<ProductFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ProductFile::class, 'product_file_id');
    }
}
