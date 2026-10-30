<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

class GlobalSearchMeilisearchFallbackTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_falls_back_to_the_database_when_meilisearch_is_unreachable(): void
    {
        Log::spy();

        $blog = Blog::factory()->published()->create([
            'title' => 'Searchable Lighthouse Post',
            'excerpt' => 'Database path should still find this.',
        ]);

        $engine = $this->throwingMeilisearch(new CommunicationException('connection refused'));

        $response = $this->get(route('search.results', ['q' => 'Lighthouse']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('query', 'Lighthouse')
            ->where('results.blogs.meta.total', 1)
            ->where('results.blogs.items.0.id', $blog->id)
            ->where('results.blogs.items.0.title', 'Searchable Lighthouse Post')
        );

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Meilisearch unreachable')
                && ($context['exception'] ?? null) === CommunicationException::class);

        $this->assertSame(1, $engine->callCount);

        $second = $this->get(route('search.results', ['q' => 'Lighthouse']));

        $second->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('results.blogs.meta.total', 1)
            ->where('results.blogs.items.0.id', $blog->id)
        );

        $this->assertSame(1, $engine->callCount, 'Open circuit must skip Meilisearch on the next request');
    }

    #[Test]
    public function it_still_fails_loudly_on_meilisearch_auth_errors(): void
    {
        Blog::factory()->published()->create(['title' => 'Searchable Auth Post']);

        $this->throwingMeilisearch($this->apiException(401));

        $this->get(route('search.results', ['q' => 'Auth']))
            ->assertStatus(500);
    }

    private function throwingMeilisearch(\Throwable $exception): ThrowingMeilisearchEngine
    {
        $engine = new ThrowingMeilisearchEngine($exception);

        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => $engine);

        config([
            'search.driver' => 'meilisearch',
            'scout.driver' => 'meilisearch',
            'scout.prefix' => '',
            'scout.queue' => false,
            'search.meilisearch_fallback.circuit_seconds' => 60,
        ]);

        return $engine;
    }

    private function apiException(int $status): ApiException
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getReasonPhrase')->willReturn('Unauthorized');

        return new ApiException($response, ['message' => "HTTP {$status}"]);
    }
}

class ThrowingMeilisearchEngine extends NullEngine
{
    public int $callCount = 0;

    public function __construct(private readonly \Throwable $exception) {}

    public function search(Builder $builder)
    {
        $this->callCount++;

        throw $this->exception;
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        $this->callCount++;

        throw $this->exception;
    }
}
