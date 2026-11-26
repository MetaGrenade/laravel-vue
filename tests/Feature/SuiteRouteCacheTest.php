<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The suite reads the route files once and gives every later application the same routes compiled (Tests\TestCase). That is what
 * keeps a run inside its memory, and it is only right while the compiled routes are the ones the files declare.
 */
class SuiteRouteCacheTest extends TestCase
{
    #[Test]
    public function an_application_after_the_first_is_given_the_compiled_routes(): void
    {
        // There is a cache by now whatever ran before: setting a test up takes it from the first application there is.
        $this->refreshApplication();

        $this->assertInstanceOf(CompiledRouteCollection::class, $this->app['router']->getRoutes());
    }

    #[Test]
    public function the_compiled_routes_are_the_ones_the_route_files_declare(): void
    {
        $this->refreshApplication();
        $compiled = $this->routes();

        $cache = CachedState::$cachedRoutes;
        CachedState::$cachedRoutes = null;

        try {
            $this->refreshApplication();
            $this->assertNotInstanceOf(CompiledRouteCollection::class, $this->app['router']->getRoutes());
            $declared = $this->routes();
        } finally {
            CachedState::$cachedRoutes = $cache;
        }

        $this->assertGreaterThan(300, count($declared));
        $this->assertSame(array_keys($declared), array_keys($compiled));

        foreach ($declared as $route => $description) {
            $this->assertSame($description, $compiled[$route], $route);
        }
    }

    /**
     * Everything about each route that decides which request reaches it and what runs for it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function routes(): array
    {
        $routes = [];

        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $routes[implode('|', $route->methods()).' '.$route->getDomain().'/'.ltrim($route->uri(), '/')] = [
                // The compiler needs a name for every route and makes one up where the files give none.
                'name' => str_starts_with((string) $route->getName(), 'generated::') ? null : $route->getName(),
                'action' => $route->getActionName(),
                'middleware' => $route->middleware(),
                'without' => $route->excludedMiddleware(),
                'wheres' => $route->wheres,
                'defaults' => $route->defaults,
                'binding fields' => $route->bindingFields(),
                'with trashed' => $route->allowsTrashedBindings(),
                'scoped bindings' => $route->enforcesScopedBindings(),
                'fallback' => $route->isFallback,
            ];
        }

        return $routes;
    }
}
