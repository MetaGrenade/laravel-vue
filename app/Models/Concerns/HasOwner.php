<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\Ownership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * For models with nullable `owner_type` / `owner_id` columns. A null owner is
 * a guest record, reachable only through its own secret link.
 *
 * @mixin Model
 */
trait HasOwner
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignOwner(?Model $owner): static
    {
        $this->owner_type = $owner?->getMorphClass();
        $this->owner_id = $owner?->getKey();

        return $this;
    }

    public function isOwnedBy(Model $owner): bool
    {
        return $this->owner_type === $owner->getMorphClass()
            && (string) $this->owner_id === (string) $owner->getKey();
    }

    /**
     * Whether any owner this user may act for owns the record.
     */
    public function isOwnedByAnyOwnerOf(User $user): bool
    {
        foreach (Ownership::ownersFor($user) as $owner) {
            if ($this->isOwnedBy($owner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query
            ->where($this->qualifyColumn('owner_type'), $owner->getMorphClass())
            ->where($this->qualifyColumn('owner_id'), $owner->getKey());
    }

    /**
     * Records owned by anyone this user may act for.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            foreach (Ownership::ownersFor($user) as $owner) {
                $query->orWhere(fn (Builder $inner) => $inner->ownedBy($owner));
            }
        });
    }
}
