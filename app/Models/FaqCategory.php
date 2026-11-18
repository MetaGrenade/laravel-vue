<?php

namespace App\Models;

use App\Support\Search\CascadedDocuments;
use App\Support\Search\DocumentsLeaving;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class FaqCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'order',
    ];

    /**
     * The documents of the questions this category is being deleted with, found by `deleting` and forgotten by `deleted`.
     */
    private ?DocumentsLeaving $documentsLeaving = null;

    protected static function booted(): void
    {
        // The questions of the category go with it by ON DELETE CASCADE, which runs no model events, so Scout would never hear of it.
        static::deleting(function (FaqCategory $category): void {
            $category->documentsLeaving = app(CascadedDocuments::class)->before([
                Faq::class => DB::table('faqs')->where('faq_category_id', $category->id),
            ]);
        });

        static::deleted(function (FaqCategory $category): void {
            app(CascadedDocuments::class)->after($category->documentsLeaving);
            $category->documentsLeaving = null;
        });
    }

    /**
     * @return HasMany<Faq, $this>
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }
}
