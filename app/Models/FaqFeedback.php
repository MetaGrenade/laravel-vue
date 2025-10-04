<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqFeedback extends Model
{
    use HasFactory;

    protected $table = 'faq_feedback';

    protected $fillable = [
        'faq_id',
        'user_id',
        'is_helpful',
    ];

    protected $casts = [
        'is_helpful' => 'boolean',
    ];

    /**
     * @return BelongsTo<Faq, self>
     */
    public function faq(): BelongsTo
    {
        return $this->belongsTo(Faq::class);
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
