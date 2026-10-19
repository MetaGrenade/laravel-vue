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
 * Every call has a timeout. A read that fails on the network, with a 5xx or with a 429 is repeated after the waits in
 * `tebex.http.retry_backoff_ms`; anything else (a 4xx other than 429) is final, since asking again gives the same
 * answer. A call that changes something (making a basket, adding a package to it) is repeated only after a 429, which
 * says it was not done: after a lost reply or a 5xx it may have been, and doing it twice would put two of a package in
 * a basket. Whatever goes wrong is thrown as a {@see TebexException} with a message for staff: the public token is
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
     * Start a basket: where the customer comes back to, what to give back to the shop in the webhook, and whose
     * address the buyer is at (Tebex needs it from a backend, or it takes the server's).
     *
     * A Minecraft or Overwolf store takes the buyer's `username` here and answers with the id it recognised it as.
     *
     * @param  array<string, scalar|null>  $custom
     *
     * @throws TebexException
     */
    public function createBasket(string $completeUrl, string $cancelUrl, array $custom, ?string $ipAddress, ?string $username = null): TebexBasket
    {
        $token = $this->token();

        return $this->basketFrom($this->post("/accounts/{$token}/baskets", array_filter([
            'complete_url' => $completeUrl,
            'cancel_url' => $cancelUrl,
            'custom' => (object) $custom,
            'complete_auto_redirect' => true,
            'ip_address' => $ipAddress,
            'username' => $username,
        ], fn ($value) => $value !== null)));
    }

    /**
     * Put a package in a basket, by the identifier Tebex gives it (`ident`).
     *
     * The path is the one in Tebex's OpenAPI schema; its guide shows another, with the account in it. Which is right
     * has to be checked against a real store (M2b).
     *
     * @param  array<string, scalar>|null  $variableData  What the package needs to be delivered to the right person
     *                                                    (a game account's `username_id`, a Discord id, a server).
     *
     * @throws TebexException
     */
    public function addPackage(string $basketIdent, string $packageIdent, int $quantity, ?array $variableData = null): TebexBasket
    {
        return $this->basketFrom($this->post('/baskets/'.rawurlencode($basketIdent).'/packages', array_filter([
            'package_id' => $packageIdent,
            'quantity' => $quantity,
            'variable_data' => $variableData === null || $variableData === [] ? null : (object) $variableData,
        ], fn ($value) => $value !== null)));
    }

    /**
     * Where the buyer can sign in to authorise a basket (Steam, Discord...), as Tebex offers them for this store, and
     * where they come back to afterwards.
     *
     * @return list<array{name: string, url: string}>
     *
     * @throws TebexException
     */
    public function signInOptions(string $basketIdent, string $returnUrl): array
    {
        $body = $this->get('/baskets/'.rawurlencode($basketIdent).'/auth', ['returnUrl' => $returnUrl])->json();

        // Documented as a list of {name, url}; read from `data` as well, in case Tebex wraps it like its other answers.
        $options = is_array($body) && array_key_exists('data', $body) ? $body['data'] : $body;

        if (! is_array($options) || ! array_is_list($options)) {
            throw new TebexException('Tebex sent a sign-in answer the shop could not understand.');
        }

        $found = [];

        foreach ($options as $option) {
            if (is_array($option) && is_string($option['url'] ?? null) && preg_match('#^https?://#i', $option['url']) === 1) {
                $found[] = ['name' => is_string($option['name'] ?? null) && $option['name'] !== '' ? $option['name'] : 'Sign in', 'url' => $option['url']];
            }
        }

        return $found;
    }

    /**
     * Take a package out of a basket. A basket with nothing in it cannot be paid, which is how an unpaid checkout the
     * shop is done with is closed.
     *
     * @throws TebexException
     */
    public function removePackage(string $basketIdent, string $packageId): TebexBasket
    {
        return $this->basketFrom($this->post('/baskets/'.rawurlencode($basketIdent).'/packages/remove', ['package_id' => $packageId]));
    }

    /**
     * A basket as Tebex has it now.
     *
     * @throws TebexException
     */
    public function basket(string $basketIdent): TebexBasket
    {
        return $this->basketFrom($this->get('/baskets/'.rawurlencode($basketIdent)));
    }

    /**
     * @throws TebexException
     */
    private function basketFrom(Response $response): TebexBasket
    {
        $data = $this->data($response);

        if (! is_array($data) || ! is_string($data['ident'] ?? null) || $data['ident'] === '') {
            throw new TebexException('Tebex sent a basket the shop could not understand.');
        }

        return TebexBasket::fromApi($data);
    }

    /**
     * @param  array<string, scalar>  $query
     *
     * @throws TebexException
     */
    private function get(string $path, array $query = []): Response
    {
        $token = $this->token();

        return $this->send('GET', "/accounts/{$token}{$path}", $query, repeatable: true);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws TebexException
     */
    private function post(string $url, array $body): Response
    {
        // The token belongs in the account paths only; checked here so that a call cannot go out without one.
        $this->token();

        return $this->send('POST', $url, $body, repeatable: false);
    }

    /**
     * @param  array<string, mixed>  $data  The query of a GET, the JSON body of anything else.
     * @param  bool  $repeatable  Whether asking again after a failure that may have got through is harmless.
     *
     * @throws TebexException
     */
    private function send(string $method, string $url, array $data, bool $repeatable): Response
    {
        try {
            $response = $this->request($repeatable)->send($method, $url, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
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

    private function request(bool $repeatable): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('tebex.api_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('tebex.http.timeout', 10))
            ->connectTimeout((int) config('tebex.http.connect_timeout', 5))
            ->retry(
                array_values((array) config('tebex.http.retry_backoff_ms', [])),
                when: fn (Throwable $exception) => $this->isTransient($exception, $repeatable),
                throw: false,
            );
    }

    /**
     * Worth asking again: Tebex asked us to slow down (so the call was not made), or, for a call that is harmless to
     * repeat, the network failed or Tebex had a problem (so the call may or may not have been made).
     */
    private function isTransient(Throwable $exception, bool $repeatable): bool
    {
        if ($exception instanceof ConnectionException) {
            return $repeatable;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || ($repeatable && $status >= 500);
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
