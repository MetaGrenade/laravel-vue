<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAssignmentRule extends Model
{
    protected $fillable = [
        'support_ticket_category_id',
        'priority',
        'assignee_type',
        'assigned_to',
        'support_team_id',
        'position',
        'active',
    ];

    protected $casts = [
        'active' => 'bool',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupportTicketCategory::class, 'support_ticket_category_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(SupportTeam::class, 'support_team_id');
    }
}
