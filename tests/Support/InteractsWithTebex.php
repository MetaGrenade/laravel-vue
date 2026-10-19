<?php

namespace Tests\Support;

use App\Payments\Providers\TebexProvider;
use App\Payments\Tebex\TebexClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Tebex without a network: the Headless API answered by `Http::fake()` with payloads shaped like Tebex's own
 * (`tests/Fixtures/tebex`, written from Tebex's published OpenAPI schema and webhook documentation).
 *
 * The shape of what Tebex sends is the one thing a fixture cannot prove, which is why the milestone has a live check
 * (M2b) that compares these files with a real store. Until then they are the source of truth for the tests.
 */
trait InteractsWithTebex
{
    /**
     * A made-up public token, set by {@see self::setUpTebex()}. It is built there, from a repeated letter, so that
     * nothing in the source looks like a key to the secrets scan (gitleaks flagged the first literal one).
     */
    protected string $tebexToken = '';

    /**
     * Configure Tebex with a test token, short retry waits, and no real sleeping.
     */
    protected function setUpTebex(): void
    {
        $this->tebexToken = 'spk3-'.str_repeat('a', 24);

        config([
            'tebex.public_token' => $this->tebexToken,
            'tebex.private_key' => 'private-key-that-must-never-be-shown',
            // Without the webhook's secret Tebex is not ready to sell (nothing could settle an order), so a test that
            // sells through it has one. Made from a repeated letter for the secrets scan, like the token.
            'tebex.webhook.secret' => 'whsec-'.str_repeat('b', 24),
            'tebex.http.retry_backoff_ms' => [1, 1, 1],
        ]);

        Sleep::fake();
    }

    /**
     * A fixture, decoded.
     *
     * @return array<string, mixed>
     */
    protected function tebexFixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/tebex/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Answer the store and catalogue endpoints with the fixtures, or with these instead.
     *
     * @param  array<string, mixed>|null  $categories  The whole body to send for the categories.
     */
    protected function fakeTebex(?array $categories = null, ?array $store = null): void
    {
        $this->fakeTebexHttp([
            'headless.tebex.io/api/accounts/*/categories*' => Http::response($categories ?? $this->tebexFixture('categories-with-packages')),
            'headless.tebex.io/api/accounts/*' => Http::response($store ?? $this->tebexFixture('webstore')),
        ]);
    }

    /**
     * Answer with these instead of whatever was faked before. (`Http::fake()` adds to what is already faked and the
     * first match wins, so a test that changes Tebex's answer part way has to start again.)
     *
     * @param  array<string, mixed>  $stubs
     */
    protected function fakeTebexHttp(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    /**
     * Tebex's basket API as a stateful stand-in (see {@see FakeTebexBaskets}), answering the store and the catalogue
     * from the fixtures as well.
     */
    protected function fakeTebexBaskets(?array $store = null): FakeTebexBaskets
    {
        $baskets = new FakeTebexBaskets;

        $this->fakeTebexHttp([
            'headless.tebex.io/api/accounts/*/categories*' => Http::response($this->tebexFixture('categories-with-packages')),
            'headless.tebex.io/*' => fn (Request $request) => $request->method() === 'GET' && preg_match('#^/api/accounts/[^/]+$#', (string) parse_url($request->url(), PHP_URL_PATH)) === 1
                ? Http::response($store ?? $this->tebexFixture('webstore'))
                : $baskets->respond($request),
        ]);

        return $baskets;
    }

    /**
     * The store fixture, set up for this platform instead.
     *
     * @return array<string, mixed>
     */
    protected function tebexStoreOn(?string $platform): array
    {
        $store = $this->tebexFixture('webstore');
        $store['data']['platform_type'] = $platform;

        return $store;
    }

    /**
     * Make Tebex the store's payment provider, as it would be once it can be chosen.
     */
    protected function sellThroughTebex(): void
    {
        config([
            'commerce.providers.tebex' => TebexProvider::class,
            'commerce.provider' => 'tebex',
        ]);
    }

    /**
     * The fixture's catalogue with these changes applied by a callback to its `data`.
     *
     * @param  callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>  $change
     * @return array<string, mixed>
     */
    protected function tebexCategoriesChanged(callable $change): array
    {
        $body = $this->tebexFixture('categories-with-packages');
        $body['data'] = $change($body['data']);

        return $body;
    }

    protected function tebexClient(): TebexClient
    {
        return app(TebexClient::class);
    }
}
