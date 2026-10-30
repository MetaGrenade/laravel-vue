<?php

namespace App\Support\Search;

use Illuminate\Support\Str;

/**
 * The updates Meilisearch accepted and then refused (its tasks that failed), for the health check and for a rebuild that has to know
 * whether its own went through (docs/search-index.md).
 */
final readonly class RefusedUpdates
{
    /**
     * @param  int  $count  How many were refused.
     * @param  bool  $more  There are more than were counted (only the first page is read).
     * @param  string|null  $firstReason  Why the first one was.
     */
    public function __construct(
        public int $count = 0,
        public bool $more = false,
        public ?string $firstReason = null,
    ) {}

    public function any(): bool
    {
        return $this->count > 0;
    }

    /**
     * In words, ending with a full stop: "Meilisearch refused 3 updates since the last rebuild began: Document does not have a key."
     */
    public function sentence(string $when): string
    {
        $number = $this->more ? $this->count.' or more' : (string) $this->count;
        $reason = $this->firstReason !== null ? ': '.rtrim($this->firstReason, '.') : '';

        return "Meilisearch refused {$number} ".Str::plural('update', $this->count)." {$when}{$reason}.";
    }
}
