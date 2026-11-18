<?php

namespace App\Support\Search;

use App\Observers\SearchIndexObserver;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Deleting a board, a category or an account takes other rows with it by `ON DELETE CASCADE`, and the database runs no model event for a
 * row it removes, so Scout never heard that a thread, a FAQ or a blog post was gone: its document stayed in the index for good, and
 * with it the title and the words of whatever a member had written (docs/search-index.md).
 *
 * It is done in two steps, as the files of the same rows are: {@see before()} finds the keys while the rows exist, and {@see after()}
 * asks the index to forget them once the deletion has happened, so a deletion that is called off, or fails, takes nothing out of the index.
 * Removing a document needs only its key, which is why the rows can be gone by then.
 */
final class CascadedDocuments
{
    /** The number of documents asked for at a time: one job with the queue, one request to Meilisearch without it. */
    public const BATCH = 500;

    public function __construct(private readonly SearchIndexObserver $searchIndex) {}

    /**
     * @param  array<class-string<Model>, Builder>  $remove  Per searchable model, a query for the rows the database is about to remove (their `id` is read).
     * @param  Closure(): list<int>|null  $rebuildThreads  The ids of the threads that stay and have a visible reply that goes, when there are some.
     */
    public function before(array $remove, ?Closure $rebuildThreads = null): DocumentsLeaving
    {
        // The null engine (the database search) keeps nothing, so there is nothing to ask and nothing to look up.
        if (! SearchDriver::keepsAnIndex()) {
            return new DocumentsLeaving;
        }

        $keys = [];

        foreach ($remove as $model => $rows) {
            $found = [];

            foreach ($rows->pluck('id') as $id) {
                $found[] = (int) $id;
            }

            if ($found !== []) {
                $keys[$model] = $found;
            }
        }

        return new DocumentsLeaving($keys, $rebuildThreads === null ? [] : $rebuildThreads());
    }

    /**
     * Forget them. The rows are gone and the deletion is done, so a failure here (the index down, the queue not taking a job) is reported
     * and goes no further: the health check counts the documents that are left and says to rebuild the index.
     */
    public function after(?DocumentsLeaving $leaving): void
    {
        if ($leaving === null) {
            return;
        }

        foreach ($leaving->remove as $model => $keys) {
            foreach (array_chunk($keys, self::BATCH) as $batch) {
                $this->attempt(fn () => $this->documents($model, $batch)->unsearchable());
            }
        }

        if ($leaving->rebuildThreads !== []) {
            $this->attempt(fn () => $this->searchIndex->afterMemberContentTakedown([], $leaving->rebuildThreads));
        }
    }

    /**
     * Models that have nothing but their key, which is all that removing a document needs. With the queue they are sent the same way, and
     * Scout's own job restores them from the key alone, since the row would not be found.
     *
     * @param  class-string<Model>  $model
     * @param  list<int>  $keys
     * @return Collection<int, Model>
     */
    private function documents(string $model, array $keys): Collection
    {
        $documents = new Collection;

        foreach ($keys as $key) {
            $document = new $model;
            $document->setAttribute($document->getKeyName(), $key);
            $documents->push($document);
        }

        return $documents;
    }

    private function attempt(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
