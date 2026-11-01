<?php

namespace App\Support\Queues;

/**
 * The queues a worker has to read for the app to work. `queue:work --queue=` replaces the connection's own queue instead of adding to
 * it, so a worker told only about `mail` stops running the jobs that name no queue, and a worker told nothing never sees `mail`.
 */
final class WorkerQueues
{
    /**
     * The queues the app names itself, whatever the connection's own queue is: a notification's email is queued on `mail`, and its
     * database and push copies on `default`. tests/Unit/QueueConfigTest.php fails when the app starts using one that is not here.
     */
    public const NAMED = ['default', 'mail'];

    /**
     * The connection's own queue (`queue.connections.{connection}.queue`: DB_QUEUE, REDIS_QUEUE, `default` unless set), where every job
     * that names no queue goes, then the queues the app names.
     *
     * @return list<string>
     */
    public static function forConnection(?string $connection = null): array
    {
        $connection = $connection ?: (string) config('queue.default');
        $own = config("queue.connections.$connection.queue");

        $queues = [...(is_string($own) ? explode(',', $own) : []), ...self::NAMED];

        return array_values(array_unique(array_filter(array_map('trim', $queues), fn (string $queue) => $queue !== '')));
    }
}
