<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SupportAssignmentRule extends Model
{
    protected static bool $relatedModelEventsRegistered = false;

    private const CACHE_KEY = 'support_assignment_rules:ordered';

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

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());

        if (! static::$relatedModelEventsRegistered) {
            static::$relatedModelEventsRegistered = true;

            User::saved(fn () => static::flushCache());
            User::deleted(fn () => static::flushCache());

            SupportTeam::saved(fn () => static::flushCache());
            SupportTeam::deleted(fn () => static::flushCache());
        }
    }

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

    public static function cachedForAssignment(): Collection
    {
        return Cache::rememberForever(static::CACHE_KEY, function () {
            return static::query()
                ->with([
                    'assignee:id,nickname,email',
                    'team:id,name',
                ])
                ->orderBy('position')
                ->orderBy('id')
                ->get();
        });
    }

    public static function flushCache(): void
    {
        Cache::forget(static::CACHE_KEY);
    }
}
