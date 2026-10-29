<?php

namespace App\Models;

use App\Models\Concerns\HasSearchDocument;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faq extends Model
{
    use HasFactory;
    use HasSearchDocument;

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

    public function searchableAs(): string
    {
        return $this->searchIndexAs('faqs');
    }

    public function shouldBeSearchable(): bool
    {
        return filter_var($this->published, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    /**
     * @return array<string, string>
     */
    public function toSearchableArray(): array
    {
        return $this->searchDocument([
            'question' => (string) $this->question,
            'answer' => SearchText::plain($this->answer),
            'category' => $this->categoryName(),
        ]);
    }

    private function categoryName(): string
    {
        if ($this->faq_category_id === null) {
            return '';
        }

        $name = FaqCategory::query()->whereKey($this->faq_category_id)->value('name');

        return is_string($name) ? $name : '';
    }
}
