<?php

namespace App\Observers;

use App\Support\Forum\ForumIndexCache;
use Illuminate\Database\Eloquent\Model;

class ForumIndexCacheObserver
{
    public function __construct(private readonly ForumIndexCache $cache)
    {
    }

    public function saved(Model $model): void
    {
        $this->cache->clear();
    }

    public function deleted(Model $model): void
    {
        $this->cache->clear();
    }

    public function restored(Model $model): void
    {
        $this->cache->clear();
    }

    public function forceDeleted(Model $model): void
    {
        $this->cache->clear();
    }
}
