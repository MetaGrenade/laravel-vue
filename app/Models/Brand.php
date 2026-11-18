<?php

namespace App\Models;

use App\Support\Search\CascadedDocuments;
use App\Support\Search\DocumentsLeaving;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    /**
     * The documents of the products this brand is being deleted from, found by `deleting` and sent again by `deleted`.
     */
    private ?DocumentsLeaving $documentsLeaving = null;

    protected static function booted(): void
    {
        // A product carries the name of its brand in its search document (`brand`), and nobody asks the index about it again when the
        // brand is renamed: the rows are all the same, the name is not.
        static::updated(function (Brand $brand): void {
            if ($brand->wasChanged('name')) {
                app(CascadedDocuments::class)->refresh([
                    Product::class => DB::table('products')->where('brand_id', $brand->id),
                ]);
            }
        });

        // The products stay without a brand by ON DELETE SET NULL, which runs no model events either, so the keys are found while they
        // still name it and the documents are sent again once it is gone.
        static::deleting(function (Brand $brand): void {
            $brand->documentsLeaving = app(CascadedDocuments::class)->before([], null, [
                Product::class => DB::table('products')->where('brand_id', $brand->id),
            ]);
        });

        static::deleted(function (Brand $brand): void {
            app(CascadedDocuments::class)->after($brand->documentsLeaving);
            $brand->documentsLeaving = null;
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
