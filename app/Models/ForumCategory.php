<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'access_permission',
        'is_published',
        'position',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function boards(): HasMany
    {
        return $this->hasMany(ForumBoard::class)->orderBy('position');
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        $permissionNames = $user?->getAllPermissions()->pluck('name')->all() ?? [];

        return $query
            ->where('is_published', true)
            ->where(function (Builder $builder) use ($permissionNames): void {
                $builder->whereNull('access_permission')
                    ->orWhere('access_permission', '');

                if (!empty($permissionNames)) {
                    $builder->orWhereIn('access_permission', $permissionNames);
                }
            });
    }

    public function canBeViewedBy(?User $user): bool
    {
        if (!$this->is_published) {
            return false;
        }

        if ($this->access_permission === null || $this->access_permission === '') {
            return true;
        }

        return $user?->can($this->access_permission) ?? false;
    }
}
