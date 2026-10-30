<?php

namespace App\Support\Search;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Throwable;

/**
 * Asks Meilisearch how it is, for the ACP card and `metaforge:search:health` (docs/search-index.md): is it reachable, does it hold the
 * documents the database says it should, are its settings the coded ones, and were any updates refused.
 *
 * It only reads, waits no longer than `search.health.timeout_seconds` for any answer, and never puts the key in a message.
 */
final class SearchHealth
{
    private readonly SearchTasks $tasks;

    public function __construct(
        private readonly Client $client,
        private readonly MeilisearchCircuitBreaker $circuit,
    ) {
        $this->tasks = new SearchTasks($client);
    }

    /**
     * A client for the configured host and key that gives up quickly, unlike the one Scout uses to index and search.
     */
    public static function clientFromConfig(): Client
    {
        $timeout = max(1, (int) config('search.health.timeout_seconds', 3));
        $key = config('scout.meilisearch.key');

        return new Client(
            (string) config('scout.meilisearch.host'),
            // The client sends no header for a null key or an empty one alike.
            is_string($key) ? $key : null,
            new HttpClient(['timeout' => $timeout, 'connect_timeout' => min($timeout, 2)]),
        );
    }

    public function check(): SearchHealthReport
    {
        if (! SearchDriver::usesMeilisearch()) {
            return new SearchHealthReport(SearchHealthReport::UNUSED, (string) config('search.driver'));
        }

        $host = (string) config('scout.meilisearch.host');
        $synced = SearchIndexState::lastSyncedAt();
        $reindexed = SearchIndexState::lastReindexedAt();
        $circuitOpen = $this->circuit->isOpen();

        $without = fn (string $problem) => new SearchHealthReport(
            SearchHealthReport::DOWN,
            'meilisearch',
            $host,
            circuitOpen: $circuitOpen,
            lastSyncedAt: $synced?->toIso8601String(),
            lastReindexedAt: $reindexed?->toIso8601String(),
            problems: [$problem],
        );

        try {
            $this->client->health();
        } catch (Throwable $exception) {
            return $without("Meilisearch cannot be reached at {$host}, so search is served from the database until it can be ({$this->short($exception)}).");
        }

        $version = null;

        try {
            $answer = $this->client->version();
            $version = is_string($answer['pkgVersion'] ?? null) ? $answer['pkgVersion'] : null;
        } catch (ApiException $exception) {
            if ($exception->httpStatus === 401) {
                return $without($this->refused(401));
            }
            // Any other refusal means a key that may not ask for the version, which is no reason to call search unhealthy.
        } catch (Throwable) {
            // As above.
        }

        $indexes = [];
        $problems = [];
        $notes = [];
        $names = [];
        $missing = [];

        foreach (SearchIndexSettings::forScout() as $modelClass => $coded) {
            $name = (new $modelClass)->indexableAs();
            $names[] = $name;
            $expected = $modelClass::makeAllSearchableQuery()->count();

            try {
                $index = $this->client->index($name);
                $stats = $index->stats();
                $live = $index->getSettings();
            } catch (ApiException $exception) {
                if ($exception->httpStatus === 404) {
                    $indexes[] = ['name' => $name, 'exists' => false, 'documents' => null, 'expected' => $expected, 'indexing' => false, 'settings_in_sync' => null];
                    $missing[] = $name;

                    continue;
                }

                if (in_array($exception->httpStatus, [401, 403], true)) {
                    return $without($this->refused((int) $exception->httpStatus));
                }

                $problems[] = "The [{$name}] index could not be read ({$this->short($exception)}).";

                continue;
            } catch (Throwable $exception) {
                return $without("Meilisearch stopped answering at {$host} ({$this->short($exception)}).");
            }

            $documents = (int) ($stats['numberOfDocuments'] ?? 0);
            $indexing = (bool) ($stats['isIndexing'] ?? false);
            $inSync = $this->settingsMatch($coded, $live);

            $indexes[] = ['name' => $name, 'exists' => true, 'documents' => $documents, 'expected' => $expected, 'indexing' => $indexing, 'settings_in_sync' => $inSync];

            if (! $inSync) {
                $problems[] = "The [{$name}] settings are not the ones this app expects. Run: php artisan metaforge:search:sync";
            }

            if ($indexing) {
                $notes[] = "The [{$name}] index is still being updated, so its count may change.";
            } elseif ($documents !== $expected) {
                $problems[] = "The [{$name}] index has {$documents} ".Str::plural('document', $documents)." and {$expected} should be there. Run: php artisan metaforge:search:reindex";
            }
        }

        if ($missing !== []) {
            // On a fresh install every index is missing: one line says so, not four.
            array_unshift($problems, count($missing) === count($names)
                ? 'No search index exists yet. Run: php artisan metaforge:search:reindex'
                : 'The '.Arr::join(array_map(fn (string $name) => "[{$name}]", $missing), ', ', ' and ').' '.(count($missing) === 1 ? 'index does' : 'indexes do').' not exist. Run: php artisan metaforge:search:reindex');
        }

        $refused = $this->refusedUpdates($names);

        if ($refused->any()) {
            $problems[] = $refused->sentence(SearchIndexState::lastReindexFromTask() !== null ? 'since the last rebuild began' : 'so far');
        }

        if ($circuitOpen) {
            $notes[] = 'Search is on the database for a short while after an earlier failure; it goes back by itself (SEARCH_MEILISEARCH_CIRCUIT_SECONDS).';
        }

        if (! config('scout.queue')) {
            // Found by saving a post with Meilisearch down: the post is stored and the request then fails.
            $notes[] = 'Posts are indexed while they are saved (SCOUT_QUEUE=false), so saving one while Meilisearch is down fails. Set SCOUT_QUEUE=true to index on the queue.';
        }

        return new SearchHealthReport(
            $problems === [] ? SearchHealthReport::HEALTHY : SearchHealthReport::ATTENTION,
            'meilisearch',
            $host,
            $version,
            $circuitOpen,
            $synced?->toIso8601String(),
            $reindexed?->toIso8601String(),
            $refused->count,
            $indexes,
            $problems,
            $notes,
        );
    }

    /**
     * Whether the index has the settings this app codes. Only what the app sets is compared: Meilisearch answers with more.
     *
     * @param  array<string, mixed>  $coded
     * @param  array<string, mixed>  $live
     */
    private function settingsMatch(array $coded, array $live): bool
    {
        foreach (['searchableAttributes', 'filterableAttributes', 'sortableAttributes', 'rankingRules'] as $key) {
            if (($live[$key] ?? null) !== ($coded[$key] ?? null)) {
                return false;
            }
        }

        $wanted = $this->plain($coded['typoTolerance'] ?? []);
        $found = $this->plain($live['typoTolerance'] ?? []);

        return ($found['enabled'] ?? null) === ($wanted['enabled'] ?? null)
            && ($found['minWordSizeForTypos']['oneTypo'] ?? null) === ($wanted['minWordSizeForTypos']['oneTypo'] ?? null)
            && ($found['minWordSizeForTypos']['twoTypos'] ?? null) === ($wanted['minWordSizeForTypos']['twoTypos'] ?? null);
    }

    /**
     * A setting as a plain array. The client answers some settings (typo tolerance, faceting) as objects, which a cast to array turns
     * into their private properties.
     *
     * @return array<string, mixed>
     */
    private function plain(mixed $value): array
    {
        return $value instanceof \Traversable ? iterator_to_array($value) : (array) $value;
    }

    /**
     * What Meilisearch refused of the updates to these indexes: from the task the last rebuild began at (so the rebuild's own updates,
     * which it did after the command that sent them had finished, are among them), or all of it when no rebuild is on record.
     *
     * @param  list<string>  $names
     */
    private function refusedUpdates(array $names): RefusedUpdates
    {
        try {
            return $this->tasks->refusedFrom($names, SearchIndexState::lastReindexFromTask());
        } catch (Throwable) {
            // A key that cannot read tasks is not a reason to call search unhealthy.
            return new RefusedUpdates;
        }
    }

    /**
     * Meilisearch answers 401 when it was given no key, and 403 both for a key that is not valid and for one that may not do what was
     * asked, in the same words, so the second cannot tell which it is.
     */
    private function refused(int $status): string
    {
        return $status === 401
            ? 'Meilisearch needs a key and was not given one (HTTP 401). Set MEILISEARCH_KEY.'
            : "Meilisearch refused the key (HTTP {$status}): it is not a valid key, or it may not do something the app needs. Check MEILISEARCH_KEY and see \"Keys\" in docs/search-index.md.";
    }

    /**
     * What went wrong in one line, without the "(see https://...)" pointer cURL adds to its messages.
     */
    private function short(Throwable $exception): string
    {
        $message = (string) preg_replace('/\s*\(see https?:\/\/\S+\)/', '', $exception->getMessage());

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $message)), 200);
    }
}
