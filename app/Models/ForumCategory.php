<?php

namespace App\Models;

use App\Support\Forum\ForumAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForumCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'position',
    ];

    protected static function booted(): void
    {
        // The boards, threads and replies of the category go with it by ON DELETE CASCADE, which runs no model events: the files go first.
        static::deleting(function (ForumCategory $category): void {
            app(ForumAttachments::class)->forgetInCategory($category);
        });
    }

    public function boards(): HasMany
    {
        return $this->hasMany(ForumBoard::class)->orderBy('position');
    }
}
