<?php

namespace Tests\Live\Redis;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * A job that leaves a mark in the cache, so a test can tell that a worker ran it.
 */
class LiveRedisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MARK = 'live-redis-job-ran';

    public function handle(): void
    {
        Cache::put(self::MARK, true, 60);
    }
}
