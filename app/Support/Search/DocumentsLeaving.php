<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Model;

/**
 * What the search index has to settle once a change has happened, found by {@see CascadedDocuments::before()} while the rows that name
 * it still exist.
 */
final readonly class DocumentsLeaving
{
    /**
     * @param  array<class-string<Model>, list<int>>  $remove  The keys of the documents of rows the database removes with the deleted one, per model.
     * @param  list<int>  $rebuildThreads  The threads that stay and whose document holds a reply that goes.
     * @param  array<class-string<Model>, list<int>>  $refresh  The keys of the documents of rows that stay and carry a name that goes or changes, per model.
     */
    public function __construct(
        public array $remove = [],
        public array $rebuildThreads = [],
        public array $refresh = [],
    ) {}
}
