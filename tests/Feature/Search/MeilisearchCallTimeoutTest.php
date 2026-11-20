<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Support\Search\MeilisearchCircuitBreaker;
use App\Support\Search\MeilisearchHttp;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Scout\EngineManager;
use Meilisearch\Client;
use Meilisearch\Exceptions\CommunicationException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use ReflectionProperty;
use Tests\TestCase;

/**
 * A call to Meilisearch that is never answered is given up on (docs/search-index.md, "When Meilisearch does not answer").
 *
 * Scout builds its client without an HTTP client, so the library used Guzzle's own, which waits for ever. A Meilisearch that takes the
 * connection and says nothing then held the request that searched (and one that saved a post with SCOUT_QUEUE=false), and the circuit
 * breaker, which opens when a call fails, never saw a failure. Measured before the change: a server that accepted and never answered held
 * the call for the 40 seconds it was kept.
 *
 * The two tests that call a silent server read the limit off the client first and fail at that point if there is none: without one the call
 * would not come back, and the test would hang instead of failing.
 */
class MeilisearchCallTimeoutTest extends TestCase
{
    use RefreshDatabase;

    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        parent::tearDown();
    }

    // --- The numbers --------------------------------------------------------------------------------------

    #[Test]
    public function the_defaults_in_the_class_and_in_the_config_file_are_the_same_numbers(): void
    {
        $this->assertSame(MeilisearchHttp::TIMEOUT, config('search.http.timeout_seconds'));
        $this->assertSame(MeilisearchHttp::CONSOLE_TIMEOUT, config('search.http.console_timeout_seconds'));
        $this->assertSame(MeilisearchHttp::CONNECT_TIMEOUT, config('search.http.connect_timeout_seconds'));
        $this->assertSame(['timeout' => 5, 'connect_timeout' => 2], MeilisearchHttp::options(false));
        $this->assertSame(['timeout' => 30, 'connect_timeout' => 2], MeilisearchHttp::options(true));
    }

    #[Test]
    public function a_web_request_and_a_worker_wait_for_the_limits_their_own_settings_name(): void
    {
        config([
            'search.http.timeout_seconds' => 7,
            'search.http.console_timeout_seconds' => 90,
            'search.http.connect_timeout_seconds' => 4,
        ]);

        $this->assertSame(['timeout' => 7, 'connect_timeout' => 4], MeilisearchHttp::options(false));
        $this->assertSame(['timeout' => 90, 'connect_timeout' => 4], MeilisearchHttp::options(true));
    }

    #[Test]
    public function a_zero_is_kept_and_a_setting_that_is_missing_gets_the_default(): void
    {
        config(['search.http.timeout_seconds' => 0, 'search.http.connect_timeout_seconds' => 0]);

        $this->assertSame(['timeout' => 0, 'connect_timeout' => 0], MeilisearchHttp::options(false), '0 is what the setting says: no limit');

        // A search.php written before these settings existed: the defaults, and not the 0 that would be no limit.
        config(['search.http' => []]);

        $this->assertSame(['timeout' => 5, 'connect_timeout' => 2], MeilisearchHttp::options(false));
        $this->assertSame(['timeout' => 30, 'connect_timeout' => 2], MeilisearchHttp::options(true));
    }

    // --- Where the limits end up ----------------------------------------------------------------------------

    #[Test]
    public function the_client_scout_resolves_has_the_limits(): void
    {
        config(['search.http.console_timeout_seconds' => 11, 'search.http.connect_timeout_seconds' => 3]);

        $options = $this->limitsOf(app(Client::class));

        $this->assertSame(11, $options['timeout'], 'a test runs as a command, so it gets the worker\'s limit');
        $this->assertSame(3, $options['connect_timeout']);
    }

    #[Test]
    public function the_engine_scout_searches_and_indexes_with_is_given_that_client(): void
    {
        config(['search.http.console_timeout_seconds' => 13]);

        $engine = $this->app->make(EngineManager::class)->createMeilisearchDriver();

        $this->assertSame(13, $this->limitsOf($engine)['timeout']);
    }

    #[Test]
    public function a_web_request_builds_the_client_with_the_shorter_limit(): void
    {
        config(['search.http.timeout_seconds' => 6, 'search.http.console_timeout_seconds' => 60]);

        $this->assertSame(6, $this->limitsOf(MeilisearchHttp::client(console: false))['timeout']);
        $this->assertSame(60, $this->limitsOf(MeilisearchHttp::client(console: true))['timeout']);
    }

    // --- A server that never answers --------------------------------------------------------------------------

    #[Test]
    public function a_call_to_a_meilisearch_that_never_answers_fails_when_the_limit_is_reached_and_not_before(): void
    {
        $port = $this->silentServer();
        config([
            'scout.meilisearch.host' => "http://127.0.0.1:{$port}",
            'scout.meilisearch.key' => 'a-key-for-this-test',
            'search.http.console_timeout_seconds' => 2,
        ]);

        $client = app(Client::class);
        $this->assertSame(2, $this->limitsOf($client)['timeout']);

        $started = microtime(true);

        try {
            $client->health();
            $this->fail('A server that never answers was answered.');
        } catch (CommunicationException $exception) {
            $waited = microtime(true) - $started;

            // The exception the circuit breaker already takes for "unreachable".
            $this->assertTrue((new MeilisearchCircuitBreaker)->shouldFallback($exception));
        }

        $this->assertGreaterThanOrEqual(1.8, $waited, 'it waited for the whole limit, so a call that is only slow is not cut off');
        $this->assertLessThan(10, $waited, 'and not for ever');

        // What was sent is what Scout's own client would have sent: the host's path, the key, and Scout as the agent.
        $request = $this->whatTheServerWasAsked();
        $this->assertStringStartsWith('GET /health ', $request);
        $this->assertStringContainsString('Authorization: Bearer a-key-for-this-test', $request);
        $this->assertStringContainsString('Meilisearch Laravel Scout', $request);
    }

    #[Test]
    public function search_is_answered_from_the_database_while_meilisearch_does_not_answer(): void
    {
        // Made while the search is still the database's, so that saving it does not go to the silent server.
        $blog = Blog::factory()->published()->create(['title' => 'Lighthouse for a silent index']);

        $port = $this->silentServer();
        config([
            'search.driver' => 'meilisearch',
            'scout.driver' => 'meilisearch',
            'scout.prefix' => '',
            'scout.queue' => false,
            'scout.meilisearch.host' => "http://127.0.0.1:{$port}",
            'search.http.console_timeout_seconds' => 1,
            'search.meilisearch_fallback.circuit_seconds' => 60,
        ]);

        $this->assertSame(1, $this->limitsOf(app(Client::class))['timeout']);

        $started = microtime(true);
        $this->get(route('search.results', ['q' => 'Lighthouse']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Search/Results')
                ->where('results.blogs.meta.total', 1)
                ->where('results.blogs.items.0.id', $blog->id));

        $this->assertLessThan(15, microtime(true) - $started, 'the visitor was answered, from the database');
        $this->assertTrue((new MeilisearchCircuitBreaker)->isOpen(), 'and the next ones will not wait for a host that is not answering');
    }

    // --- Helpers -------------------------------------------------------------------------------------------

    /**
     * A server that accepts the connection (the operating system does, from its queue) and then says nothing, the way a stuck one does.
     */
    private function silentServer(): int
    {
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $error);
        $this->assertNotFalse($this->server, "could not listen on a local port: {$error}");

        return (int) substr((string) strrchr((string) stream_socket_get_name($this->server, false), ':'), 1);
    }

    /**
     * The first request the silent server was sent (it is already in the queue of the connection it accepted for the client).
     */
    private function whatTheServerWasAsked(): string
    {
        $connection = stream_socket_accept($this->server, 1);
        $this->assertNotFalse($connection, 'the client never connected');
        stream_set_timeout($connection, 1);
        $request = (string) fread($connection, 8192);
        fclose($connection);

        return $request;
    }

    /**
     * The `timeout` and `connect_timeout` of the Guzzle client somewhere inside an object (the Meilisearch client, or Scout's engine). It
     * reads them without making a call, so that a client with no limit fails the test here and not by never coming back.
     *
     * @return array{timeout: int|float, connect_timeout: int|float}
     */
    private function limitsOf(object $root): array
    {
        $guzzle = $this->guzzleIn($root, 5, []);

        $this->assertInstanceOf(HttpClient::class, $guzzle, 'no Guzzle client was found inside '.$root::class);

        $config = (new ReflectionProperty(HttpClient::class, 'config'))->getValue($guzzle);
        $this->assertGreaterThan(0, $config['timeout'] ?? 0, 'the client waits for ever for an answer');

        return ['timeout' => $config['timeout'], 'connect_timeout' => $config['connect_timeout'] ?? 0];
    }

    /**
     * @param  list<int>  $seen
     */
    private function guzzleIn(mixed $value, int $depth, array $seen): ?HttpClient
    {
        if ($value instanceof HttpClient) {
            return $value;
        }

        if (! is_object($value) || $depth === 0 || in_array(spl_object_id($value), $seen, true)) {
            return null;
        }

        $seen[] = spl_object_id($value);

        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($value)) {
                continue;
            }

            $found = $this->guzzleIn($property->getValue($value), $depth - 1, $seen);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
