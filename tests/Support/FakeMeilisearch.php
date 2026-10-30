<?php

namespace Tests\Support;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\ForumThread;
use App\Models\Product;
use App\Support\Search\SearchIndexSettings;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Meilisearch\Client;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * A stand-in Meilisearch that answers by method and path, so the health check goes through the real client without an engine. It starts
 * as a healthy one holding no documents, with the settings the app codes; a test changes what it answers and reads what it was asked.
 */
final class FakeMeilisearch
{
    /** @var array<string, array{int, array<mixed>}|Throwable> */
    private array $answers = [];

    /** @var list<string> Every request, as "GET /path?query". */
    public array $requests = [];

    /**
     * @param  array<string, int>  $documents  How many documents each index holds, by name without the prefix.
     */
    public static function serving(array $documents = []): self
    {
        $fake = new self;
        $fake->on('GET /health', ['status' => 'available']);
        $fake->on('GET /version', ['commitSha' => 'abc', 'commitDate' => '2025-01-30T10:52:23Z', 'pkgVersion' => '1.12.8']);
        $fake->on('GET /tasks', ['results' => [], 'total' => 0, 'limit' => 1, 'from' => null, 'next' => null]);

        foreach (self::models() as $name => $model) {
            $fake->on("GET /indexes/{$name}/stats", ['numberOfDocuments' => $documents[$name] ?? 0, 'isIndexing' => false, 'fieldDistribution' => []]);
            $fake->on("GET /indexes/{$name}/settings", self::liveSettings($model));
        }

        return $fake;
    }

    /**
     * What an index answers when it has the coded settings: those, and the rest Meilisearch adds.
     *
     * @param  class-string  $model
     * @return array<string, mixed>
     */
    public static function liveSettings(string $model): array
    {
        $coded = SearchIndexSettings::forScout()[$model];
        $coded['typoTolerance'] += ['disableOnWords' => [], 'disableOnAttributes' => []];

        return $coded + ['displayedAttributes' => ['*'], 'stopWords' => [], 'synonyms' => [], 'distinctAttribute' => null, 'pagination' => ['maxTotalHits' => 1000]];
    }

    /**
     * @return array<string, class-string>
     */
    public static function models(): array
    {
        return ['blogs' => Blog::class, 'forum_threads' => ForumThread::class, 'faqs' => Faq::class, 'products' => Product::class];
    }

    /**
     * @param  array<mixed>|Throwable  $answer  A body, or an exception to throw as if the host could not be reached.
     */
    public function on(string $route, array|Throwable $answer, int $status = 200): self
    {
        $this->answers[$route] = $answer instanceof Throwable ? $answer : [$status, $answer];

        return $this;
    }

    /**
     * Answer as Meilisearch does when it refuses a request: a status and an error body.
     */
    public function refusing(string $route, int $status, string $code = 'invalid_api_key', string $type = 'auth'): self
    {
        return $this->on($route, ['message' => 'The API key is not valid for this.', 'code' => $code, 'type' => $type, 'link' => 'https://docs.meilisearch.com/errors'], $status);
    }

    public function unreachable(string $route = 'GET /health'): self
    {
        return $this->on($route, new ConnectException('cURL error 7: Failed to connect to meili.test port 7700 after 3 ms (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)', new Request('GET', 'http://meili.test/health')));
    }

    public function client(string $host = 'http://meili.test', ?string $key = 'fake-key'): Client
    {
        $handler = HandlerStack::create(function (RequestInterface $request) {
            // The client asks for `/tasks/`; Meilisearch answers it as `/tasks`.
            $path = rtrim($request->getUri()->getPath(), '/');
            $query = $request->getUri()->getQuery();
            $this->requests[] = $request->getMethod().' '.$path.($query !== '' ? '?'.urldecode($query) : '');

            $answer = $this->answers[$request->getMethod().' '.$path] ?? [404, ['message' => 'Index not found.', 'code' => 'index_not_found', 'type' => 'invalid_request', 'link' => 'https://docs.meilisearch.com/errors']];

            if ($answer instanceof Throwable) {
                return Create::rejectionFor($answer);
            }

            return Create::promiseFor(new Response($answer[0], ['Content-Type' => 'application/json'], (string) json_encode($answer[1])));
        });

        return new Client($host, $key, new HttpClient(['handler' => $handler]));
    }

    /**
     * Whether a request was made whose line starts with this.
     */
    public function asked(string $prefix): bool
    {
        foreach ($this->requests as $request) {
            if (str_starts_with($request, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
