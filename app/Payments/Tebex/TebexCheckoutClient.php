<?php

namespace App\Payments\Tebex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to the Tebex Checkout API (https://docs.tebex.io/developers/checkout-api). EXPERIMENTAL: see `tebex.checkout_api`.
 *
 * It is not the Headless API ({@see TebexClient}): another address, other credentials (HTTP Basic with the project id and
 * the private key), and answers that are not wrapped in a `data` member. It follows the same rules, though. Every call has
 * a timeout; a read that fails on the network, with a 5xx or with a 429 is repeated after the waits in
 * `tebex.http.retry_backoff_ms`; a call that changes something (making a basket, taking a row out of it) is repeated only
 * after a 429, which says it was not done, because after a lost reply or a 5xx it may have been. Whatever goes wrong is
 * thrown as a {@see TebexException} with a message for staff, with the project id and key taken out; neither is ever in a
 * URL.
 *
 * Tests use `Http::fake()`; see `Tests\Support\FakeTebexCheckoutApi`.
 */
class TebexCheckoutClient
{
    public function isConfigured(): bool
    {
        return filled(config('tebex.checkout_api.project_id')) && filled(config('tebex.private_key'));
    }

    /**
     * Make a basket from what the buyer is paying for, in one request. `$items` are Tebex's `items`: each a custom
     * `package` (name, price, type, custom data) and a `qty`, which sits beside the package, not inside it, or Tebex
     * ignores it.
     *
     * @param  array<string, mixed>  $basket  The buyer, where they come back to, and the data Tebex hands back in the webhooks.
     * @param  list<array<string, mixed>>  $items
     *
     * @throws TebexException
     */
    public function checkout(array $basket, array $items, string $currency): TebexCheckoutBasket
    {
        return $this->basketFrom($this->send('POST', '/checkout', ['basket' => $basket, 'items' => $items], repeatable: false), $currency);
    }

    /**
     * A basket as Tebex has it now.
     *
     * @throws TebexException
     */
    public function basket(string $ident, string $currency): TebexCheckoutBasket
    {
        return $this->basketFrom($this->send('GET', '/baskets/'.rawurlencode($ident), [], repeatable: true), $currency);
    }

    /**
     * Take a row out of a basket. A basket with nothing in it cannot be paid, which is how an unpaid checkout the shop is
     * done with is closed.
     *
     * @throws TebexException
     */
    public function removeRow(string $ident, int $rowId): void
    {
        $this->send('DELETE', '/baskets/'.rawurlencode($ident).'/packages/'.$rowId, [], repeatable: false);
    }

    /**
     * A payment as Tebex has it, by its transaction id (`tbx-...`): the same shape as the payment a webhook carries.
     *
     * @return array<string, mixed>
     *
     * @throws TebexException
     */
    public function payment(string $transaction): array
    {
        return $this->paymentFrom($this->send('GET', '/payments/'.rawurlencode($transaction), ['type' => 'txn_id'], repeatable: true));
    }

    /**
     * Refund a payment. Tebex takes no amount: the whole payment goes back. The answer is the payment, with the status it
     * has now (refunded, or a refund pending).
     *
     * @return array<string, mixed>
     *
     * @throws TebexException
     */
    public function refund(string $transaction): array
    {
        return $this->paymentFrom($this->send('POST', '/payments/'.rawurlencode($transaction).'/refund?type=txn_id', [], repeatable: false));
    }

    /**
     * Find out whether the project id and private key are accepted, without changing anything. The Checkout API has no call
     * that says who is asking, so a basket that does not exist is asked for: Tebex answers a refusal of the credentials with
     * a 401 or 403, and anything about the basket itself (a 404) means it let the request in.
     *
     * @throws TebexException When Tebex cannot be reached or does not accept the credentials.
     */
    public function ping(): void
    {
        try {
            $this->send('GET', '/baskets/connection-test', [], repeatable: true);
        } catch (TebexException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TebexException
     */
    private function paymentFrom(Response $response): array
    {
        $body = $response->json();

        // The documented answer is the payment itself; one wrapped like the Headless API's is read as well.
        $data = is_array($body) && is_array($body['data'] ?? null) && ! isset($body['transaction_id']) ? $body['data'] : $body;

        if (! is_array($data) || ! is_string($data['transaction_id'] ?? null) || $data['transaction_id'] === '') {
            throw new TebexException('Tebex sent a payment the shop could not understand.');
        }

        return $data;
    }

    /**
     * @throws TebexException
     */
    private function basketFrom(Response $response, string $currency): TebexCheckoutBasket
    {
        $body = $response->json();

        // The documented answer is the basket itself; one wrapped like the Headless API's is read as well.
        $data = is_array($body) && is_array($body['data'] ?? null) && ! isset($body['ident']) ? $body['data'] : $body;

        if (! is_array($data) || ! is_string($data['ident'] ?? null) || $data['ident'] === '') {
            throw new TebexException('Tebex sent a basket the shop could not understand.');
        }

        return TebexCheckoutBasket::fromApi($data, $currency);
    }

    /**
     * @param  array<string, mixed>  $data  The query of a GET, the JSON body of anything else.
     * @param  bool  $repeatable  Whether asking again after a failure that may have got through is harmless.
     *
     * @throws TebexException
     */
    private function send(string $method, string $path, array $data, bool $repeatable): Response
    {
        [$project, $key] = $this->credentials();

        try {
            $options = $method === 'GET' ? ['query' => $data] : ($data === [] ? [] : ['json' => $data]);
            $response = $this->request($project, $key, $repeatable)->send($method, $path, $options);
        } catch (ConnectionException $exception) {
            throw new TebexException('Tebex could not be reached: '.$this->redact($exception->getMessage()), previous: $exception);
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        return $response;
    }

    private function request(string $project, string $key, bool $repeatable): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('tebex.checkout_api.url'), '/'))
            ->withBasicAuth($project, $key)
            ->acceptJson()
            ->asJson()
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
     * Tebex describes an error as an RFC 7807 problem: `title`, `detail`, `status`.
     */
    private function failure(Response $response): TebexException
    {
        $status = $response->status();
        $problem = $response->json();

        $title = is_array($problem) && is_string($problem['title'] ?? null) ? $problem['title'] : null;
        $detail = is_array($problem) && is_string($problem['detail'] ?? null) ? $problem['detail'] : null;

        $reason = match (true) {
            in_array($status, [401, 403], true) => 'it refused the credentials: check the project id and private key, and that Tebex has approved the Checkout API for this account',
            $status === 404 => 'it does not know what was asked for',
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
     * @return array{0: string, 1: string}
     *
     * @throws TebexException
     */
    private function credentials(): array
    {
        $project = config('tebex.checkout_api.project_id');
        $key = config('tebex.private_key');

        if (! is_string($project) || trim($project) === '' || ! is_string($key) || trim($key) === '') {
            throw new TebexException('Tebex\'s Checkout API is not set up: add the project id (TEBEX_PROJECT_ID) and the private key (TEBEX_PRIVATE_KEY) to the environment.');
        }

        return [trim($project), trim($key)];
    }

    /**
     * Take the credentials out of text that came from the network, so they are not repeated in logs or on screen.
     */
    private function redact(string $text): string
    {
        foreach ([config('tebex.checkout_api.project_id'), config('tebex.private_key')] as $secret) {
            if (is_string($secret) && trim($secret) !== '') {
                $text = str_replace(trim($secret), '[hidden]', $text);
            }
        }

        return $text;
    }
}
