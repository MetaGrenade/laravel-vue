<?php

namespace Tests\Live\Redis;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Throwable;

/**
 * A test against a real Redis (docs/redis.md). These are not in the suites `php artisan test` runs: the CI redis job runs them with
 * `php artisan test tests/Live/Redis`, against a Redis container, and they skip themselves unless `REDIS_LIVE_HOST` says where one is.
 *
 * They switch the cache, the queue and the sessions to that Redis, which is how a production site is set up, and they empty both of the
 * databases the app uses (`cache:clear` is one of the things they check). So they will not start against a Redis that already holds keys.
 */
abstract class LiveRedisTestCase extends TestCase
{
    use RefreshDatabase;

    /** The connections in config/database.php: the cache has its own, everything else is on the default one. */
    private const CONNECTIONS = ['default', 'cache'];

    /** Set once both databases were found empty, because only then is whatever is in them this test's own to remove. */
    private bool $mayEmptyRedis = false;

    protected function setUp(): void
    {
        $host = getenv('REDIS_LIVE_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('Set REDIS_LIVE_HOST (and REDIS_LIVE_PORT) to run these against a real Redis.');
        }

        parent::setUp();

        $port = getenv('REDIS_LIVE_PORT');

        foreach (self::CONNECTIONS as $connection) {
            config([
                "database.redis.$connection.url" => null,
                "database.redis.$connection.host" => $host,
                "database.redis.$connection.port" => is_string($port) && $port !== '' ? $port : '6379',
                "database.redis.$connection.password" => null,
            ]);
        }

        config([
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'session.driver' => 'redis',
        ]);

        // The app was built before the settings above, and the things below read them when they are first used.
        foreach (['redis', 'cache', 'cache.store', 'queue', 'queue.connection', 'session', 'session.store'] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        Facade::clearResolvedInstances();

        foreach (self::CONNECTIONS as $connection) {
            if ((int) Redis::connection($connection)->dbsize() > 0) {
                $this->markTestSkipped("The Redis at $host already holds keys on the '$connection' connection; these tests empty it, so they need a Redis of their own.");
            }
        }

        $this->mayEmptyRedis = true;
    }

    protected function tearDown(): void
    {
        // tearDown runs after a skip in setUp too, and a Redis that was refused must be left exactly as it was found.
        if ($this->mayEmptyRedis) {
            foreach (self::CONNECTIONS as $connection) {
                try {
                    Redis::connection($connection)->flushdb();
                } catch (Throwable) {
                    // The test may have failed because Redis could not be reached.
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Which connections hold this key. The key is given as the cache store writes it, prefix and all.
     *
     * @return list<string>
     */
    protected function holders(string $key): array
    {
        return array_values(array_filter(
            self::CONNECTIONS,
            fn (string $connection) => (int) Redis::connection($connection)->exists($key) > 0,
        ));
    }

    /**
     * What the cache store puts in front of a key; sessions and locks are keys of the same store, on another connection.
     */
    protected function cachePrefix(): string
    {
        return Cache::store('redis')->getStore()->getPrefix();
    }
}
