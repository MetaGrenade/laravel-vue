<?php

namespace App\Support\Search;

use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;

/**
 * Meilisearch's tasks: it answers an update at once and does it afterwards, and may then refuse it. A rebuild uses these to know that
 * its own updates went through, and the health check to see what was refused (docs/search-index.md).
 *
 * A point in time is a task number, not a time: the app's clock and Meilisearch's can differ, and a time is only kept to the second.
 */
final class SearchTasks
{
    /** The most refused updates read at once. */
    private const PAGE = 100;

    public function __construct(private readonly Client $client) {}

    /**
     * The number the next task will have: the point a rebuild starts from. Nothing before it is the rebuild's.
     */
    public function nextUid(): int
    {
        $newest = $this->client->getTasks((new TasksQuery)->setLimit(1))->getResults()[0]['uid'] ?? null;

        return is_int($newest) ? $newest + 1 : 0;
    }

    /**
     * Wait, up to this many seconds, until nothing is waiting or being done for these indexes. Returns whether that happened.
     *
     * @param  list<string>  $indexes
     */
    public function settle(array $indexes, int $seconds): bool
    {
        $attempts = max(1, $seconds * 4);

        for ($attempt = 1; ; $attempt++) {
            if ($this->pending($indexes) === 0) {
                return true;
            }

            if ($attempt >= $attempts) {
                return false;
            }

            Sleep::usleep(250_000);
        }
    }

    /**
     * What was refused: for these indexes, from this task number on (oldest first, so the reason is the first thing that went wrong),
     * or all of it when there is no point to count from. The `total` Meilisearch gives ignores where we start, so what is counted is
     * what comes back.
     *
     * @param  list<string>  $indexes
     */
    public function refusedFrom(array $indexes, ?int $uid): RefusedUpdates
    {
        $query = (new TasksQuery)->setStatuses(['failed'])->setIndexUids($indexes)->setLimit(self::PAGE);

        if ($uid !== null) {
            $query->setReverse(true)->setFrom($uid);
        }

        $found = $this->client->getTasks($query);
        $message = $found->getResults()[0]['error']['message'] ?? null;
        $reason = is_string($message) ? Str::limit($message, 200) : null;

        return $uid === null
            ? new RefusedUpdates($found->getTotal(), false, $reason)
            : new RefusedUpdates(count($found->getResults()), $found->getNext() > 0, $reason);
    }

    /**
     * @param  list<string>  $indexes
     */
    private function pending(array $indexes): int
    {
        return $this->client
            ->getTasks((new TasksQuery)->setStatuses(['enqueued', 'processing'])->setIndexUids($indexes)->setLimit(1))
            ->getTotal();
    }
}
