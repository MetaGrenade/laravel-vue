<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faq extends Model
{
    use HasFactory;

    protected $fillable = [
        'faq_category_id',
        'question',
        'answer',
        'order',
        'published',
    ];

    /**
     * @return BelongsTo<FaqCategory, self>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'faq_category_id');
    }

    /**
     * @return HasMany<FaqFeedback>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(FaqFeedback::class);
    }

    /**
     * @return HasMany<FaqFeedback>
     */
    public function helpfulFeedback(): HasMany
    {
        return $this->feedback()->where('is_helpful', true);
    }

    /**
     * @return HasMany<FaqFeedback>
     */
    public function notHelpfulFeedback(): HasMany
    {
        return $this->feedback()->where('is_helpful', false);
    }
}
