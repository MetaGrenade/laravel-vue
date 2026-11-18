<?php

namespace App\Jobs;

use App\Support\Search\CascadedDocuments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Asks the search index for some documents again, because the name one of them carries has changed: a member's nickname (`author` of
 * their threads and blog posts), a brand (`brand` of its products), a FAQ category (`category` of its questions). Docs: search-index.md.
 *
 * A document is made from the rows when it is sent, so renaming something does not change the documents that carry its name until they
 * are sent again, and searching for the new name found nothing while the old one kept finding what no longer had it.
 * {@see CascadedDocuments::refresh()} finds the keys and queues them; the rename itself only reads ids.
 *
 * Each job takes {@see self::CHUNK} documents, so a member with thousands of threads becomes many short jobs that never run long enough
 * to be cut off, as {@see NotifyMembers} does. A key that is no longer a row, or is no longer to be listed (an unpublished thread, an
 * inactive product), is left alone: it is not in the index, or the change that took it out has already asked for it to be removed.
 */
class RefreshSearchDocuments implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** How many documents one job sends. */
    public const CHUNK = 100;

    /** Three times: sending the same documents again changes nothing, so a try that stopped halfway is safe to repeat. */
    public int $tries = 3;

    /**
     * @param  class-string  $model  A model that uses Scout's `Searchable`, which is a trait and cannot be said as a type.
     * @param  list<int>  $keys
     */
    public function __construct(
        public readonly string $model,
        public readonly array $keys,
    ) {}

    /**
     * Queue the keys in jobs of {@see self::CHUNK}, once the request's transaction (if any) has been committed: a worker that picked a job
     * up first would build the documents from the name as it was.
     *
     * @param  class-string  $model  A model that uses Scout's `Searchable`.
     * @param  list<int>  $keys  Duplicates are sent once.
     */
    public static function dispatchInChunks(string $model, array $keys): void
    {
        foreach (array_chunk(array_values(array_unique($keys)), self::CHUNK) as $chunk) {
            self::dispatch($model, $chunk)->afterCommit();
        }
    }

    public function handle(): void
    {
        // Scout's own import: it leaves out what is not to be listed, and sends the documents itself, in the worker that is already
        // running this, or as one more job when `scout.queue` is on.
        $this->model::query()->whereKey($this->keys)->searchable();
    }
}
