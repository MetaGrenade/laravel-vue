<?php

namespace Tests\Live\Redis;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * What the app does once its cache, queue and sessions are on Redis: where each of them lives, what clearing the cache leaves alone,
 * when a job is let into the queue, and that a worker runs one and takes it off again (docs/redis.md).
 */
class RedisLiveTest extends LiveRedisTestCase
{
    #[Test]
    public function the_cache_has_a_database_of_its_own_and_everything_else_shares_the_other(): void
    {
        // A page, through the whole stack: it starts a session and stores it where sessions go.
        $this->get('/')->assertOk();
        $sessionKey = $this->cachePrefix().session()->getId();

        Cache::put('live-cached', 'a page', 60);
        LiveRedisJob::dispatch();
        $lock = Cache::lock('live-lock', 60);
        $this->assertTrue($lock->get());

        $this->assertSame(['cache'], $this->holders($this->cachePrefix().'live-cached'), 'the cache belongs in its own database, the one that can be emptied');
        $this->assertSame(['default'], $this->holders($sessionKey), 'a session in the cache database is lost whenever the cache is cleared');
        $this->assertSame(['default'], $this->holders($this->cachePrefix().'live-lock'), 'a lock in the cache database is let go whenever the cache is cleared');
        $this->assertSame(1, Queue::size(), 'the job is on the queue');
        $this->assertSame(['default'], $this->holders('queues:default'));
    }

    #[Test]
    public function clearing_the_cache_leaves_the_sessions_the_queue_and_the_locks_alone(): void
    {
        $this->get('/')->assertOk();
        $sessionKey = $this->cachePrefix().session()->getId();

        Cache::put('live-cached', 'a page', 60);
        LiveRedisJob::dispatch();
        $this->assertTrue(Cache::lock('live-lock', 60)->get());

        $this->assertSame(0, Artisan::call('cache:clear'));

        $this->assertNull(Cache::get('live-cached'), 'the cache is empty');
        $this->assertSame(['default'], $this->holders($sessionKey), 'everybody is still signed in');
        $this->assertSame(1, Queue::size(), 'the queued job (an email, say) is still there');
        $this->assertFalse(Cache::lock('live-lock', 60)->get(), 'a lock somebody holds is still held');
    }

    #[Test]
    public function a_lock_is_held_until_it_is_released(): void
    {
        $first = Cache::lock('live-lock', 60);
        $second = Cache::lock('live-lock', 60);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get(), 'a second taker is refused while the first holds it');

        $first->release();

        $this->assertTrue($second->get(), 'and may have it once it is let go');
    }

    #[Test]
    public function a_job_pushed_inside_a_transaction_waits_for_the_commit(): void
    {
        DB::transaction(function () {
            LiveRedisJob::dispatch();

            $this->assertSame(0, Queue::size(), 'a worker must not be able to pick it up before what it is about has been saved');
        });

        $this->assertSame(1, Queue::size());
    }

    #[Test]
    public function a_job_pushed_inside_a_transaction_that_is_rolled_back_is_never_queued(): void
    {
        try {
            DB::transaction(function () {
                LiveRedisJob::dispatch();

                throw new RuntimeException('Something went wrong after the job was dispatched.');
            });
        } catch (RuntimeException) {
            // Expected: the transaction is undone, and with it everything it was going to tell people.
        }

        $this->assertSame(0, Queue::size());
    }

    #[Test]
    public function a_worker_runs_a_job_and_takes_it_off_the_queue(): void
    {
        LiveRedisJob::dispatch();
        $this->assertNull(Cache::get(LiveRedisJob::MARK));

        $this->assertSame(0, Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']));

        $this->assertTrue(Cache::get(LiveRedisJob::MARK), 'the job ran');
        $this->assertSame(0, Queue::size(), 'and is not waiting any more');
        $this->assertSame(0, (int) Redis::connection('default')->zcard('queues:default:reserved'), 'nor is it kept as one that a worker holds, which would run it again once retry_after passes');
    }
}
