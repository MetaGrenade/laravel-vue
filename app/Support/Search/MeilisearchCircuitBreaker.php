<?php

namespace App\Support\Search;

use Illuminate\Support\Facades\Cache;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use Throwable;

/**
 * Short open circuit after Meilisearch is unreachable so search falls back
 * to the database without hammering a dead host on every request.
 */
final class MeilisearchCircuitBreaker
{
    /**
     * HTTP statuses treated as "unreachable" for fallback (not auth/misconfig).
     *
     * @var list<int>
     */
    private const FALLBACK_STATUSES = [404, 408, 502, 503, 504];

    public function isOpen(): bool
    {
        return Cache::has($this->cacheKey());
    }

    public function open(): void
    {
        $seconds = max(1, (int) config('search.meilisearch_fallback.circuit_seconds', 60));

        Cache::put($this->cacheKey(), true, now()->addSeconds($seconds));
    }

    public function shouldFallback(Throwable $exception): bool
    {
        if ($exception instanceof CommunicationException) {
            return true;
        }

        if ($exception instanceof ApiException) {
            return in_array((int) $exception->httpStatus, self::FALLBACK_STATUSES, true);
        }

        return false;
    }

    private function cacheKey(): string
    {
        return (string) config('search.meilisearch_fallback.cache_key', 'search:meilisearch:circuit_open');
    }
}
