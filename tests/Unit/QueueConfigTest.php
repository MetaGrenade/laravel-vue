<?php

namespace Tests\Unit;

use App\Jobs\PruneUsageEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The queue settings that docs/redis.md promises, held in place for the connections a site is run on. The live tests (tests/Live/Redis)
 * show what a real Redis does with them; these run everywhere.
 */
class QueueConfigTest extends TestCase
{
    /** What `queue:work --timeout` is when nobody sets it, which is how a worker started from the docs runs. */
    private const WORKER_TIMEOUT = 60;

    public function test_a_redis_queue_lets_a_job_in_only_once_the_transaction_it_was_pushed_in_has_committed(): void
    {
        // A database queue's row is part of the transaction; a Redis list is not, so without this a worker can start a job for a
        // record that has not been saved yet, and a rolled back transaction still sends its email.
        $this->assertTrue(config('queue.connections.redis.after_commit'));
    }

    public function test_a_job_is_given_back_to_the_queue_only_after_a_worker_has_had_time_to_finish_it(): void
    {
        foreach (['database', 'redis'] as $connection) {
            $this->assertGreaterThan(
                self::WORKER_TIMEOUT,
                config("queue.connections.$connection.retry_after"),
                'A worker is stopped after '.self::WORKER_TIMEOUT." seconds; if retry_after on '$connection' is not longer, a job that is still running is handed to a second worker.",
            );
        }
    }

    public function test_no_queued_class_runs_for_as_long_as_the_queue_waits_before_trying_it_again(): void
    {
        $longest = min(config('queue.connections.database.retry_after'), config('queue.connections.redis.retry_after'));
        $classes = $this->queuedClasses();

        // The scan has to find the queued classes, or the loop below passes for want of anything to look at.
        $this->assertContains(PruneUsageEvents::class, array_map(fn (ReflectionClass $class) => $class->getName(), $classes));

        foreach ($classes as $class) {
            $timeout = $this->timeoutOf($class);

            if ($timeout !== null) {
                $this->assertLessThan(
                    $longest,
                    $timeout,
                    "{$class->getName()} may run for $timeout seconds, and the queue gives a job to another worker after $longest. Raise retry_after (DB_QUEUE_RETRY_AFTER, REDIS_QUEUE_RETRY_AFTER) above it, or shorten the job.",
                );
            }
        }
    }

    /**
     * Every class in the app that is put on the queue: jobs, notifications, mail and listeners.
     *
     * @return list<ReflectionClass<object>>
     */
    private function queuedClasses(): array
    {
        $classes = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $name = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($name)) {
                continue;
            }

            $class = new ReflectionClass($name);

            if ($class->isInstantiable() && $class->implementsInterface(ShouldQueue::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function timeoutOf(ReflectionClass $class): ?int
    {
        foreach ($class->getAttributes(Timeout::class) as $attribute) {
            return $attribute->newInstance()->timeout;
        }

        if ($class->hasProperty('timeout')) {
            $default = $class->getProperty('timeout')->getDefaultValue();

            return is_int($default) ? $default : null;
        }

        return null;
    }
}
