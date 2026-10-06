<?php

namespace App\Models;

use App\Casts\SanitizedHtml;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForumPostRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'forum_post_id',
        'edited_by_id',
        'body',
        'edited_at',
    ];

    protected $casts = [
        'body' => SanitizedHtml::class.':forum',
        'edited_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'forum_post_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_id');
    }
}
