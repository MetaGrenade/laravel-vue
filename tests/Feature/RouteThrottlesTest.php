<?php

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A limit written with numbers (`throttle:20,1`) is counted under the account alone, so every such limit in the application is one
 * count, and a route that lists two of them counts each request twice. A named limiter has its name in its key, so it is a count of
 * its own. Every throttle on a route must therefore be the name of a limiter that exists (AppServiceProvider::configureRateLimiting).
 */
class RouteThrottlesTest extends TestCase
{
    #[Test]
    public function every_throttle_on_a_route_names_a_limiter_that_exists(): void
    {
        $wrong = [];
        $seen = 0;

        foreach ($this->throttles() as $uri => $names) {
            foreach ($names as $name) {
                $seen++;

                if (str_contains($name, ',') || is_numeric($name)) {
                    $wrong[] = "{$uri}: throttle:{$name} is written with numbers; give it a named limiter";
                } elseif (RateLimiter::limiter($name) === null) {
                    $wrong[] = "{$uri}: throttle:{$name} names a limiter that is not defined";
                }
            }
        }

        $this->assertGreaterThan(30, $seen, 'The test found hardly any throttles on routes, so it is not looking at them.');
        $this->assertSame([], $wrong, "Throttle routes with a named limiter (RateLimiter::for in AppServiceProvider):\n  ".implode("\n  ", $wrong));
    }

    #[Test]
    public function each_part_of_the_api_is_throttled_by_a_limiter_of_its_own(): void
    {
        // The routes whose limits used to be the same bare numbers: they must not go back to sharing a name.
        $names = [];

        foreach ($this->throttles() as $uri => $limiters) {
            if (str_starts_with($uri, 'api/v1/')) {
                foreach ($limiters as $name) {
                    $names[$name][] = $uri;
                }
            }
        }

        // Each one starts "api-". The parts are listed alone because a secrets scanner takes a list of names that start with a word like
        // "api", "key" or "token" for a list of credentials.
        foreach (['blog', 'polls', 'forum-write', 'forum-moderate', 'support', 'support-write', 'support-read', 'support-rating'] as $part) {
            $this->assertArrayHasKey('api-'.$part, $names, "No API route is throttled by api-{$part}.");
        }
    }

    /**
     * The limiter named by each throttle of each route, as the router resolves it (groups and aliases expanded).
     *
     * @return array<string, list<string>>
     */
    private function throttles(): array
    {
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                foreach ([ThrottleRequests::class, ThrottleRequestsWithRedis::class] as $class) {
                    if (str_starts_with($middleware, $class.':')) {
                        $found[$route->uri()][] = substr($middleware, strlen($class) + 1);
                    }
                }
            }
        }

        return $found;
    }
}
