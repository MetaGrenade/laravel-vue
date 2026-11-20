<?php

namespace App\Support\Search;

use GuzzleHttp\Client as HttpClient;
use Laravel\Scout\Scout;
use Meilisearch\Client;

/**
 * How long a call to Meilisearch may take before it is given up on (`SEARCH_TIMEOUT`, `SEARCH_CONNECT_TIMEOUT`,
 * `SEARCH_CONSOLE_TIMEOUT`, docs/configuration.md).
 *
 * Scout builds its Meilisearch client without an HTTP client, so the library picked Guzzle's own, which has no limit at all: a
 * Meilisearch that takes the connection and then says nothing (out of memory, a disk that has stopped, a firewall that drops packets) held
 * the request that searched, or the one that saved a post with SCOUT_QUEUE=false, for as long as it stayed silent. Measured: a server that
 * accepts and never answers held the call for the whole 40 seconds it was kept, and would have for ever. Searches are made on web
 * requests, so every one of them takes a PHP worker with it, and the pool is gone in seconds on a busy site.
 *
 * The circuit breaker (MeilisearchCircuitBreaker) was built for exactly this and could not help: it opens when a call FAILS, and a
 * call with no limit does not fail. A call that runs out of time throws a `CommunicationException`, which it already treats as "unreachable":
 * search is served from the database for `search.meilisearch_fallback.circuit_seconds` and nobody waits for a host that is not answering.
 *
 * A web request waits for a short time (an answer takes milliseconds, and each second is a PHP worker held). A queue worker or an artisan
 * command waits longer, because what it sends can be big (a rebuild sends up to 500 documents in one call) and nobody is waiting on it.
 */
final class MeilisearchHttp
{
    /** Seconds to wait for an answer on a web request, in a queue worker or command, and to make the connection, when config/search.php does not say. */
    public const TIMEOUT = 5;

    public const CONSOLE_TIMEOUT = 30;

    public const CONNECT_TIMEOUT = 2;

    /**
     * The limits of Guzzle's `timeout` and `connect_timeout` options, in seconds (0 would be no limit).
     *
     * @return array{timeout: int, connect_timeout: int}
     */
    public static function options(bool $console): array
    {
        // A key that is missing (a search.php written before these existed) gets these limits, and not 0, which would be no limit.
        return [
            'timeout' => (int) config($console ? 'search.http.console_timeout_seconds' : 'search.http.timeout_seconds', $console ? self::CONSOLE_TIMEOUT : self::TIMEOUT),
            'connect_timeout' => (int) config('search.http.connect_timeout_seconds', self::CONNECT_TIMEOUT),
        ];
    }

    /**
     * The client Scout uses to index and to search: the host and key it would have used, with the limits of `options()`.
     */
    public static function client(?bool $console = null): Client
    {
        $key = config('scout.meilisearch.key');

        return new Client(
            (string) config('scout.meilisearch.host'),
            is_string($key) ? $key : null,
            new HttpClient(self::options($console ?? app()->runningInConsole())),
            clientAgents: [sprintf('Meilisearch Laravel Scout (v%s)', Scout::VERSION)],
        );
    }
}
