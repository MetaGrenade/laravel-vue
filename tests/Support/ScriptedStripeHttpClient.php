<?php

namespace Tests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;
use Throwable;

/**
 * Stands in for Stripe's HTTP layer so the real SDK (and Cashier's client) can be exercised
 * without a network: each request is answered with the next scripted response, and every
 * request is kept for the test to look at.
 */
class ScriptedStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  list<array{0: int, 1: array<string, mixed>}|Throwable>  $responses  [status, body], or an error to throw (a dropped connection).
     */
    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

        $next = array_shift($this->responses) ?? throw new RuntimeException("Unexpected request to Stripe: {$method} {$absUrl}");

        if ($next instanceof Throwable) {
            throw $next;
        }

        [$status, $body] = $next;

        return [json_encode($body, JSON_THROW_ON_ERROR), $status, []];
    }

    /**
     * A page of a Stripe list.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function page(array $items, bool $hasMore): array
    {
        return [200, ['object' => 'list', 'data' => $items, 'has_more' => $hasMore, 'url' => '/v1/refunds']];
    }

    /**
     * @return array<string, mixed>
     */
    public static function refund(string $id, int $amount = 100): array
    {
        return [
            'id' => $id,
            'object' => 'refund',
            'amount' => $amount,
            'currency' => 'usd',
            'payment_intent' => 'pi_1',
            'status' => 'succeeded',
            'metadata' => [],
        ];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function error(int $status, string $message, string $type = 'invalid_request_error'): array
    {
        return [$status, ['error' => ['message' => $message, 'type' => $type]]];
    }
}
