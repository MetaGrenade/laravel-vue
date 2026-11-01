<?php

namespace Tests\Unit;

use App\Jobs\PruneUsageEvents;
use App\Support\Queues\WorkerQueues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Queue;
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

    public function test_every_worker_command_in_the_repo_is_started_through_metaforge_queue(): void
    {
        // `queue:work` reads one queue: the one --queue names, or else the connection's own. Notification emails are queued on `mail`, so
        // a worker started either way never sent one, and nothing failed to say so. A --queue written into the command line is no better:
        // it replaces DB_QUEUE / REDIS_QUEUE, and a site that sets one stops running the jobs that name no queue. `metaforge:queue`
        // looks the connection's queue up and adds the ones the app names.
        $commands = $this->workerCommands();
        $found = array_column($commands, 0);

        foreach (['composer.json "dev"', 'composer.json "dev:ssr"', 'docs/redis.md Supervisor example'] as $where) {
            $this->assertContains($where, $found, "No worker command was found in $where, so it could not be checked.");
        }

        $this->assertNotEmpty(preg_grep('/^README\.md/', $found), 'No worker command was found in README.md, so it could not be checked.');

        foreach ($commands as [$where, $command]) {
            $this->assertMatchesRegularExpression(
                '/^metaforge:queue (work|listen)\b/',
                $command,
                "$where starts `php artisan $command`, which does not read every queue the app puts work on (`mail`, and the connection's own queue). Start it with `php artisan metaforge:queue work` or `listen`, with the same options.",
            );
        }
    }

    public function test_the_worker_command_reads_every_queue_the_app_puts_work_on_whatever_queue_the_connection_is_set_to(): void
    {
        $queues = $this->queuesInUse();

        // The scan has to find the queues, or the loops below pass for want of anything to look at.
        $this->assertContains('default', $queues);
        $this->assertContains('mail', $queues);

        foreach (['database', 'redis'] as $connection) {
            // DB_QUEUE / REDIS_QUEUE: where every job that names no queue goes.
            foreach (['default', 'site-jobs'] as $own) {
                config(['queue.default' => $connection, "queue.connections.$connection.queue" => $own]);

                $read = WorkerQueues::forConnection();

                foreach ([$own, ...$queues] as $queue) {
                    $this->assertContains(
                        $queue,
                        $read,
                        "With the $connection queue set to '$own', a worker started through metaforge:queue reads [".implode(', ', $read)."] and never '$queue'. Add it to WorkerQueues::NAMED, or whatever is queued there is not run.",
                    );
                }
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
        return array_values(array_filter(
            $this->appClasses(),
            fn (ReflectionClass $class) => $class->isInstantiable() && $class->implementsInterface(ShouldQueue::class),
        ));
    }

    /**
     * @return list<ReflectionClass<object>>
     */
    private function appClasses(): array
    {
        $classes = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $name = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($name)) {
                $classes[] = new ReflectionClass($name);
            }
        }

        return $classes;
    }

    /**
     * The queues the app puts work on: each channel of a notification (`viaQueues()`), what a class names with `#[Queue]` or `$queue`,
     * what is dispatched with `onQueue()`, and `default`, where everything that names no queue goes.
     *
     * @return list<string>
     */
    private function queuesInUse(): array
    {
        $queues = ['default'];

        foreach ($this->appClasses() as $class) {
            foreach ($class->getAttributes(Queue::class) as $attribute) {
                $queues[] = (string) $attribute->newInstance()->queue;
            }

            if ($class->hasProperty('queue') && is_string($default = $class->getProperty('queue')->getDefaultValue())) {
                $queues[] = $default;
            }

            if ($class->hasMethod('viaQueues') && $class->isInstantiable()) {
                // The method lists its queues and nothing else, so it does not need the arguments the constructor takes.
                $channels = $class->getMethod('viaQueues')->invoke($class->newInstanceWithoutConstructor());

                foreach ($channels as $queue) {
                    $this->assertIsString($queue, "{$class->getName()}::viaQueues() must give each channel's queue as a string.");
                    $queues[] = $queue;
                }
            }
        }

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $source = $file->getContents();
            $named = preg_match_all('/onQueue\(\s*([\'"])([^\'"]+)\1/', $source, $literals);

            // A queue chosen at run time cannot be read from here, and would slip past the guard.
            $this->assertSame(
                preg_match_all('/onQueue\(/', $source),
                $named,
                "{$file->getRelativePathname()} calls onQueue() with something other than a string literal, so the queue it names cannot be checked against the worker commands.",
            );

            array_push($queues, ...$literals[2]);
        }

        $queues = array_values(array_unique($queues));
        sort($queues);

        return $queues;
    }

    /**
     * Each worker (`metaforge:queue`, `queue:work` or `queue:listen`) a person is told to start, or that `composer dev` starts, with where
     * it is written down.
     *
     * @return list<array{string, string}>
     */
    private function workerCommands(): array
    {
        $commands = [];

        $scripts = json_decode((string) file_get_contents(base_path('composer.json')), true)['scripts'] ?? [];

        foreach ($scripts as $name => $script) {
            foreach ((array) $script as $line) {
                if (preg_match('/php artisan ((?:metaforge:queue|queue:(?:work|listen))[^"]*)/', $line, $match)) {
                    $commands[] = ["composer.json \"$name\"", trim($match[1])];
                }
            }
        }

        $readme = (string) file_get_contents(base_path('README.md'));

        if (preg_match_all('/`php artisan ((?:metaforge:queue|queue:(?:work|listen))[^`]*)`/', $readme, $found, PREG_OFFSET_CAPTURE)) {
            foreach ($found[1] as [$command, $offset]) {
                $commands[] = ['README.md line '.(substr_count($readme, "\n", 0, $offset) + 1), trim($command)];
            }
        }

        if (preg_match('/^command=.*artisan ((?:metaforge:queue|queue:(?:work|listen)).*)$/m', (string) file_get_contents(base_path('docs/redis.md')), $match)) {
            $commands[] = ['docs/redis.md Supervisor example', trim($match[1])];
        }

        return $commands;
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
