<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SupportResponseTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'is_active',
        'support_ticket_category_id',
    ];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupportTicketCategory::class, 'support_ticket_category_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(SupportTeam::class, 'support_response_template_support_team')
            ->withTimestamps();
    }
}
