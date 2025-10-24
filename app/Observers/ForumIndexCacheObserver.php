<?php

namespace App\Observers;

use App\Support\Forum\ForumIndexCache;

class ForumIndexCacheObserver
{
    public function __construct(private readonly ForumIndexCache $cache)
    {
    }

    public function saved(): void
    {
        $this->cache->clear();
    }

    public function deleted(): void
    {
        $this->cache->clear();
    }

    public function restored(): void
    {
        $this->cache->clear();
    }

    public function forceDeleted(): void
    {
        $this->cache->clear();
    }
}
