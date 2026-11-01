<?php

namespace Tests\Unit;

use App\Support\Queues\WorkerQueues;
use ArrayObject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `metaforge:queue` is how composer dev, the README and the Supervisor example in docs/redis.md start a worker (tests/Unit/QueueConfigTest.php
 * fails if one of them does not). `queue:work --queue=` replaces the connection's own queue, so the command has to put it back in the
 * list: a site with DB_QUEUE or REDIS_QUEUE set runs the jobs that name no queue there.
 */
class QueueWorkerCommandTest extends TestCase
{
    public function test_the_connections_own_queue_is_read_first_then_default_and_mail(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => 'site-jobs']);

        $this->assertSame(['site-jobs', 'default', 'mail'], WorkerQueues::forConnection());
        $this->assertSame(['site-jobs', 'default', 'mail'], WorkerQueues::forConnection('redis'));
    }

    public function test_a_connection_left_on_the_default_queue_reads_default_once(): void
    {
        config(['queue.connections.database.queue' => 'default']);

        $this->assertSame(['default', 'mail'], WorkerQueues::forConnection('database'));
    }

    public function test_the_queue_of_another_connection_is_not_read(): void
    {
        config(['queue.connections.database.queue' => 'db-jobs', 'queue.connections.redis.queue' => 'site-jobs']);

        $this->assertSame(['db-jobs', 'default', 'mail'], WorkerQueues::forConnection('database'));
    }

    public function test_a_connection_with_no_queue_of_its_own_reads_the_queues_the_app_names(): void
    {
        $this->assertSame(WorkerQueues::NAMED, WorkerQueues::forConnection('sync'));
    }

    public function test_a_list_set_as_the_connections_queue_is_kept_whole(): void
    {
        config(['queue.connections.redis.queue' => 'site-jobs, mail']);

        $this->assertSame(['site-jobs', 'mail', 'default'], WorkerQueues::forConnection('redis'));
    }

    public function test_work_starts_the_worker_on_the_default_connection_with_the_queues_and_the_options_it_was_given(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);
        $seen = $this->standIn('queue:work');

        $this->artisan('metaforge:queue work --tries=3')->assertSuccessful();

        $this->assertSame(['connection' => null, 'queue' => 'default,mail', 'tries' => '3'], $seen->getArrayCopy());
    }

    public function test_a_queue_set_for_the_connection_stays_in_the_list(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => 'site-jobs']);
        $seen = $this->standIn('queue:work');

        $this->artisan('metaforge:queue work')->assertSuccessful();

        $this->assertSame('site-jobs,default,mail', $seen['queue']);
    }

    public function test_the_connection_named_on_the_command_line_decides_which_queue_is_added(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.queue' => 'db-jobs',
            'queue.connections.redis.queue' => 'site-jobs',
        ]);
        $seen = $this->standIn('queue:work');

        $this->artisan('metaforge:queue work redis --tries=3')->assertSuccessful();

        $this->assertSame(['connection' => 'redis', 'queue' => 'site-jobs,default,mail', 'tries' => '3'], $seen->getArrayCopy());
    }

    public function test_listen_starts_queue_listen(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'site-jobs']);
        $work = $this->standIn('queue:work');
        $listen = $this->standIn('queue:listen');

        $this->artisan('metaforge:queue listen --tries=1')->assertSuccessful();

        $this->assertSame([], $work->getArrayCopy());
        $this->assertSame(['connection' => null, 'queue' => 'site-jobs,default,mail', 'tries' => '1'], $listen->getArrayCopy());
    }

    public function test_a_queue_given_on_the_command_line_is_used_as_it_is(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => 'site-jobs']);
        $seen = $this->standIn('queue:work');

        $this->artisan('metaforge:queue work --queue=reports')->assertSuccessful();

        $this->assertSame('reports', $seen['queue']);
    }

    public function test_it_refuses_anything_but_work_or_listen(): void
    {
        $seen = $this->standIn('queue:work');

        $this->artisan('metaforge:queue restart')->assertExitCode(Command::INVALID);
        $this->artisan('metaforge:queue --tries=3 work')->assertExitCode(Command::INVALID);

        $this->assertSame([], $seen->getArrayCopy());
    }

    /**
     * Stands in for one of Laravel's worker commands, so that what it is handed can be read without a worker starting.
     *
     * @return ArrayObject<string, mixed>
     */
    private function standIn(string $name): ArrayObject
    {
        $seen = new ArrayObject;

        Artisan::registerCommand(new class($name, $seen) extends Command
        {
            /** @param  ArrayObject<string, mixed>  $seen */
            public function __construct(string $name, private ArrayObject $seen)
            {
                $this->signature = "$name {connection?} {--queue=} {--tries=1}";

                parent::__construct();
            }

            public function handle(): int
            {
                $this->seen->exchangeArray([
                    'connection' => $this->argument('connection'),
                    'queue' => $this->option('queue'),
                    'tries' => $this->option('tries'),
                ]);

                return self::SUCCESS;
            }
        });

        return $seen;
    }
}
