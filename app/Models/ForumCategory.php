<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForumCategory extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            if ($category->getRawOriginal('is_published') === null && $category->is_published === null) {
                $category->is_published = true;
            }
        });
    }

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
            ->where(function (Builder $builder) {
                $builder->where('is_published', true)
                    ->orWhereNull('is_published');
            })
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
        if (!$this->isEffectivelyPublished()) {
            return false;
        }

        if ($this->access_permission === null || $this->access_permission === '') {
            return true;
        }

        return $user?->can($this->access_permission) ?? false;
    }

    public function isEffectivelyPublished(): bool
    {
        $rawValue = $this->getRawOriginal('is_published');

        if ($rawValue === null) {
            return true;
        }

        return (bool) $this->is_published;
    }
}
