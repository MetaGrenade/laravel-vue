<?php

namespace Tests\Support;

use Closure;
use RuntimeException;

/**
 * Stands in for Stripe's HTTP layer like {@see ScriptedStripeHttpClient}, but answers each call by what it is ("GET /v1/coupons/cpn_1")
 * instead of by its place in a sequence, so a test says what Stripe knows and not what order Cashier asks in. Every call is kept.
 */
class RoutedStripeHttpClient extends ScriptedStripeHttpClient
{
    /**
     * @param  array<string, array<string, mixed>|array{0: int, 1: array<string, mixed>}|Closure>  $routes  By "METHOD /v1/path": what Stripe
     *                                                                                                      answers (a body, or [status, body]), or a closure that is given the
     *                                                                                                      request's parameters and returns either.
     */
    public function __construct(private array $routes)
    {
        parent::__construct([]);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

        $key = strtoupper($method).' '.parse_url($absUrl, PHP_URL_PATH);

        $answer = $this->routes[$key] ?? throw new RuntimeException("Unexpected request to Stripe: {$key}");

        if ($answer instanceof Closure) {
            $answer = $answer($params);
        }

        [$status, $body] = array_is_list($answer) && is_int($answer[0] ?? null) ? $answer : [200, $answer];

        return [json_encode($body, JSON_THROW_ON_ERROR), $status, []];
    }

    /**
     * The calls made to one route.
     *
     * @return list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}>
     */
    public function callsTo(string $route): array
    {
        return array_values(array_filter(
            $this->requests,
            fn (array $request) => strtoupper($request['method']).' '.parse_url($request['url'], PHP_URL_PATH) === $route,
        ));
    }
}
