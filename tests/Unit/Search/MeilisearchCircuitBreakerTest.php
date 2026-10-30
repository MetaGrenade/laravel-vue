<?php

namespace Tests\Unit\Search;

use App\Support\Search\MeilisearchCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tests\TestCase;

class MeilisearchCircuitBreakerTest extends TestCase
{
    #[Test]
    public function it_starts_closed_and_opens_with_ttl(): void
    {
        config([
            'search.meilisearch_fallback.circuit_seconds' => 60,
            'search.meilisearch_fallback.cache_key' => 'search:meilisearch:circuit_open:test',
        ]);

        Cache::forget(config('search.meilisearch_fallback.cache_key'));

        $breaker = new MeilisearchCircuitBreaker;

        $this->assertFalse($breaker->isOpen());

        $breaker->open();

        $this->assertTrue($breaker->isOpen());
        $this->assertTrue(Cache::has(config('search.meilisearch_fallback.cache_key')));
    }

    #[Test]
    public function it_treats_communication_failures_as_unreachable(): void
    {
        $breaker = new MeilisearchCircuitBreaker;

        $this->assertTrue($breaker->shouldFallback(new CommunicationException('connection refused')));
    }

    #[Test]
    public function it_treats_selected_api_statuses_as_unreachable(): void
    {
        $breaker = new MeilisearchCircuitBreaker;

        foreach ([404, 408, 502, 503, 504] as $status) {
            $this->assertTrue(
                $breaker->shouldFallback($this->apiException($status)),
                "Expected HTTP {$status} to trigger fallback",
            );
        }
    }

    #[Test]
    public function it_does_not_fallback_on_auth_or_unexpected_errors(): void
    {
        $breaker = new MeilisearchCircuitBreaker;

        $this->assertFalse($breaker->shouldFallback($this->apiException(401)));
        $this->assertFalse($breaker->shouldFallback($this->apiException(403)));
        $this->assertFalse($breaker->shouldFallback($this->apiException(500)));
        $this->assertFalse($breaker->shouldFallback(new RuntimeException('boom')));
    }

    private function apiException(int $status): ApiException
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getReasonPhrase')->willReturn('Error');

        return new ApiException($response, ['message' => "HTTP {$status}"]);
    }
}
