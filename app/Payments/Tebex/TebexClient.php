<?php

namespace App\Payments\Tebex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to the Tebex Headless API (https://docs.tebex.io/developers/headless-api).
 *
 * Every call has a timeout. A request that fails on the network, with a 5xx or with a 429 is repeated after the
 * waits in `tebex.http.retry_backoff_ms`; anything else (a 4xx other than 429) is final, since asking again gives the
 * same answer. Whatever goes wrong is thrown as a {@see TebexException} with a message for staff: the public token is
 * taken out of it, and the private key is never in a URL or a message to begin with.
 *
 * Tests use `Http::fake()`; see `Tests\Support\InteractsWithTebex` for answers shaped like Tebex's own.
 */
class TebexClient
{
    public function isConfigured(): bool
    {
        return filled(config('tebex.public_token'));
    }

    /**
     * The store the public token belongs to. Also the cheapest way to find out that the token works.
     *
     * @throws TebexException
     */
    public function webstore(): TebexStore
    {
        $data = $this->data($this->get(''));

        if (! is_array($data) || ! isset($data['id'])) {
            throw new TebexException('Tebex sent an answer the shop could not understand.');
        }

        return TebexStore::fromApi($data);
    }

    /**
     * Every category of the store with its packages, as Tebex sends them.
     *
     * @return list<array<string, mixed>>
     *
     * @throws TebexException
     */
    public function categories(): array
    {
        $data = $this->data($this->get('/categories', ['includePackages' => 1]));

        if (! is_array($data) || ! array_is_list($data)) {
            throw new TebexException('Tebex sent an answer the shop could not understand.');
        }

        // Refused, never filtered out: a category left out would be taken for one Tebex no longer lists.
        foreach ($data as $category) {
            if (! is_array($category)) {
                throw new TebexException('Tebex sent a category the shop could not understand (it is not an object).');
            }
        }

        return $data;
    }

    /**
     * @param  array<string, scalar>  $query
     *
     * @throws TebexException
     */
    private function get(string $path, array $query = []): Response
    {
        $token = $this->token();

        try {
            $response = $this->request()->get("/accounts/{$token}{$path}", $query);
        } catch (ConnectionException $exception) {
            throw new TebexException('Tebex could not be reached: '.$this->redact($exception->getMessage()), previous: $exception);
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        return $response;
    }

    /**
     * The `data` member of a successful answer.
     *
     * @throws TebexException
     */
    private function data(Response $response): mixed
    {
        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('data', $body)) {
            throw new TebexException('Tebex sent an answer the shop could not understand.');
        }

        return $body['data'];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('tebex.api_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('tebex.http.timeout', 10))
            ->connectTimeout((int) config('tebex.http.connect_timeout', 5))
            ->retry(
                array_values((array) config('tebex.http.retry_backoff_ms', [])),
                when: fn (Throwable $exception) => $this->isTransient($exception),
                throw: false,
            );
    }

    /**
     * Worth asking again: the network failed, Tebex had a problem, or Tebex asked us to slow down.
     */
    private function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * Tebex describes an error as an RFC 9457 problem: `title`, `detail`, `status`.
     */
    private function failure(Response $response): TebexException
    {
        $status = $response->status();
        $problem = $response->json();

        $title = is_array($problem) && is_string($problem['title'] ?? null) ? $problem['title'] : null;
        $detail = is_array($problem) && is_string($problem['detail'] ?? null) ? $problem['detail'] : null;

        $reason = match (true) {
            in_array($status, [401, 403], true) => 'it refused the credentials: check the public token and private key',
            $status === 404 => 'it does not know this store: check the public token',
            $status === 429 => 'it is asking for fewer requests',
            $status >= 500 => 'it has a problem of its own',
            default => 'it refused the request',
        };

        $said = trim(implode(': ', array_filter([$title, $detail])));

        return new TebexException(
            "Tebex answered {$status}: {$reason}".($said !== '' ? " ({$this->redact($said)})" : '').'.',
            $status,
        );
    }

    /**
     * @throws TebexException
     */
    private function token(): string
    {
        $token = config('tebex.public_token');

        if (! is_string($token) || trim($token) === '') {
            throw new TebexException('Tebex is not set up: add the store\'s public token (TEBEX_PUBLIC_TOKEN) to the environment.');
        }

        return trim($token);
    }

    /**
     * Take the store's token out of text that came from the network, so it is not repeated in logs or on screen.
     */
    private function redact(string $text): string
    {
        $token = config('tebex.public_token');

        return is_string($token) && trim($token) !== '' ? str_replace(trim($token), '[token]', $text) : $text;
    }
}
