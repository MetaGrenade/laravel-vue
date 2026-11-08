<?php

namespace Tests\Feature;

use App\Support\OpenApi\Specification;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/api.md lists every endpoint of the API in one table. A route added without a row is an endpoint nobody finds, and a row for a
 * route that was removed or moved is a promise that cannot be kept. The same goes for the OpenAPI document that people feed to their
 * tools: it is written by hand (app/Support/OpenApi/Specification.php), so a route can be missing from it.
 */
class ApiReferenceDocsTest extends TestCase
{
    private const PAGE = 'docs/api.md';

    #[Test]
    public function every_api_route_has_a_row_in_the_reference_and_every_row_has_a_route(): void
    {
        $routes = array_keys($this->routes());
        $rows = array_keys($this->rows());

        $missing = array_values(array_diff($routes, $rows));
        $unknown = array_values(array_diff($rows, $routes));

        sort($missing);
        sort($unknown);

        $this->assertSame([], $missing, "These routes are in the API and have no row in docs/api.md:\n  ".implode("\n  ", $missing));
        $this->assertSame([], $unknown, "These rows in docs/api.md name a route the API does not have (renamed, moved or removed):\n  ".implode("\n  ", $unknown));
    }

    #[Test]
    public function every_row_names_the_scope_the_route_asks_for(): void
    {
        $wrong = [];
        $routes = $this->routes();

        foreach ($this->rows() as $key => $row) {
            if (isset($routes[$key]) && $routes[$key]['scope'] !== $row['scope']) {
                $wrong[] = sprintf('%s (line %d): the page says "%s", the route asks for "%s"', $key, $row['line'], $row['scope'], $routes[$key]['scope']);
            }
        }

        $this->assertSame([], $wrong, "The Scope column of docs/api.md is not what the routes ask for. The words are a scope name, \"any key\" (a key and no scope) or \"public\" (no key):\n  ".implode("\n  ", $wrong));
    }

    #[Test]
    public function every_row_names_the_section_whose_switch_turns_the_route_off(): void
    {
        $wrong = [];
        $routes = $this->routes();

        foreach ($this->rows() as $key => $row) {
            if (isset($routes[$key]) && $routes[$key]['section'] !== $row['section']) {
                $wrong[] = sprintf('%s (line %d): the page says "%s", the route is behind "%s"', $key, $row['line'], $row['section'], $routes[$key]['section']);
            }
        }

        $this->assertSame([], $wrong, "The Section column of docs/api.md is not the section the routes sit behind (section.enabled:). The words are a section's name, or a dash for none:\n  ".implode("\n  ", $wrong));
    }

    #[Test]
    public function the_page_names_every_scope_the_site_has(): void
    {
        $page = file_get_contents(base_path(self::PAGE));
        $missing = [];

        foreach (array_keys((array) config('api.scopes')) as $scope) {
            if (! str_contains($page, '`'.$scope.'`')) {
                $missing[] = $scope;
            }
        }

        $this->assertSame([], $missing, "These scopes are in config/api.php and are not named in docs/api.md:\n  ".implode("\n  ", $missing));
    }

    #[Test]
    public function the_openapi_document_describes_exactly_the_v1_routes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openapi');

        try {
            Specification::generate($path);

            $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($path);
        }

        $described = [];

        foreach ($document['paths'] as $address => $operations) {
            foreach (array_keys($operations) as $method) {
                $described[] = strtoupper($method).' '.$this->normalise('api'.$address);
            }
        }

        $served = array_values(array_filter(array_keys($this->routes()), fn (string $key) => str_contains($key, ' api/v1/')));

        $missing = array_values(array_diff($served, $described));
        $extra = array_values(array_diff($described, $served));

        sort($missing);
        sort($extra);

        $this->assertSame([], $missing, "These /api/v1 routes are not described in the OpenAPI document (add them in app/Support/OpenApi/Specification.php):\n  ".implode("\n  ", $missing));
        $this->assertSame([], $extra, "The OpenAPI document describes these operations and no route answers them:\n  ".implode("\n  ", $extra));
    }

    /**
     * The routes of the API, as the page sees them: the method and the address with every parameter written {}.
     *
     * @return array<string, array{scope: string, section: string}>
     */
    private function routes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

            // The documents at /api/docs are pages (the web group). The API is what answers in the api group.
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('api', $middleware, true)) {
                continue;
            }

            $scopes = [];
            $section = '—';

            foreach ($middleware as $name) {
                if (str_starts_with($name, 'scope:')) {
                    $scopes[] = substr($name, strlen('scope:'));
                }

                if (str_starts_with($name, 'section.enabled:')) {
                    $section = substr($name, strlen('section.enabled:'));
                }
            }

            $scope = $scopes !== [] ? implode(',', $scopes) : (in_array('auth:sanctum', $middleware, true) ? 'any key' : 'public');

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes[$method.' '.$this->normalise($route->uri())] = ['scope' => $scope, 'section' => $section];
                }
            }
        }

        return $routes;
    }

    /**
     * The rows of the table: | `METHOD` | `/address` | scope | section | what it does |
     *
     * @return array<string, array{scope: string, section: string, line: int}>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (preg_split('/\R/', (string) file_get_contents(base_path(self::PAGE))) ?: [] as $index => $line) {
            if (! preg_match('/^\| `(GET|POST|PUT|PATCH|DELETE)` \| `([^`]+)` \| ([^|]+) \| ([^|]+) \| /', $line, $match)) {
                continue;
            }

            $key = $match[1].' '.$this->normalise($match[2]);

            $this->assertArrayNotHasKey($key, $rows, "docs/api.md has two rows for {$key} (line ".($index + 1).').');

            $rows[$key] = ['scope' => trim($match[3], ' `'), 'section' => trim($match[4]), 'line' => $index + 1];
        }

        $this->assertNotSame([], $rows, 'No endpoint rows were found in docs/api.md: the table must keep the columns | `METHOD` | `/address` | scope | section | what it does |.');

        return $rows;
    }

    /** `/api/v1/blogs/{blog:slug}` and `/api/v1/blogs/{blog}` are the same address. */
    private function normalise(string $address): string
    {
        return ltrim((string) preg_replace('/\{[^}]+\}/', '{}', $address), '/');
    }
}
