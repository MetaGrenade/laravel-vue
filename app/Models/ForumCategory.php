<?php

namespace App\Models;

use App\Support\Forum\ForumAttachments;
use App\Support\Search\CascadedDocuments;
use App\Support\Search\DocumentsLeaving;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ForumCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'position',
    ];

    /**
     * The documents of the threads this category is being deleted with, found by `deleting` and forgotten by `deleted`.
     */
    private ?DocumentsLeaving $documentsLeaving = null;

    protected static function booted(): void
    {
        // The boards, threads and replies of the category go with it by ON DELETE CASCADE, which runs no model events: the files go first,
        // and the threads' documents in the search index are asked to go once the category is.
        static::deleting(function (ForumCategory $category): void {
            app(ForumAttachments::class)->forgetInCategory($category);

            $category->documentsLeaving = app(CascadedDocuments::class)->before([
                ForumThread::class => DB::table('forum_threads')->whereIn(
                    'forum_board_id',
                    DB::table('forum_boards')->select('id')->where('forum_category_id', $category->id),
                ),
            ]);
        });

        static::deleted(function (ForumCategory $category): void {
            app(CascadedDocuments::class)->after($category->documentsLeaving);
            $category->documentsLeaving = null;
        });
    }

    public function boards(): HasMany
    {
        return $this->hasMany(ForumBoard::class)->orderBy('position');
    }
}
