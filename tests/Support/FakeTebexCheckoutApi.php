<?php

namespace Tests\Support;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Tebex's Checkout API as a small stateful stand-in, so a test can follow a basket from being made to being paid or emptied
 * without scripting each answer.
 *
 * It answers in the shape of Tebex's OpenAPI schema (Checkout API 1.1.5): the basket itself, not wrapped in anything. It has
 * never been compared with the real thing; that is what the live check (M2b) is for. Everything it was asked is kept in
 * {@see self::$requests}, and a failure can be made to happen once or more with {@see self::failOn()}.
 */
class FakeTebexCheckoutApi
{
    /** @var array<string, array<string, mixed>> Baskets by ident. */
    public array $baskets = [];

    /** @var list<array{method: string, path: string, url: string, body: array<string, mixed>, headers: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, array{status: int, times: int, detail: string|null}> What to fail with, by action. */
    private array $failures = [];

    private int $made = 0;

    private int $rows = 0;

    /** The sales tax Tebex adds, as a share of the price before tax (0.2 is 20%). */
    public float $taxRate = 0.0;

    /** When true, taking a row out of a basket does nothing (Tebex refusing to empty it). */
    public bool $stubborn = false;

    /** What Tebex charges for each unit on top of the price it was sent (it rounds, or its price for the item has moved). */
    public float $extraPerUnit = 0.0;

    /** When true, Tebex answers a basket with no place to pay (links.checkout missing), so the shop falls back to the documented address. */
    public bool $withoutCheckoutLink = false;

    /** @var array<string, array<string, mixed>> Payments by transaction id: the shape a webhook carries. */
    public array $payments = [];

    /** What a refund leaves a payment as: 2 is refunded, 21 a refund pending, 1 not refunded at all (Tebex declining to). */
    public int $refundStatus = 2;

    /** Where the checkout links Tebex gives are. */
    public string $checkoutBase = 'https://pay.tebex.io';

    /**
     * Called with the action as Tebex receives a call and before it does anything.
     *
     * @var (Closure(string): void)|null
     */
    public ?Closure $whenAsked = null;

    /**
     * Fail the next `$times` calls of an action ('checkout', 'get', 'remove') with this status.
     */
    public function failOn(string $action, int $status, int $times = 1, ?string $detail = null): self
    {
        $this->failures[$action] = ['status' => $status, 'times' => $times, 'detail' => $detail];

        return $this;
    }

    /**
     * What Tebex does when the customer has paid: the basket is complete and offers a payment, not a way to pay.
     */
    public function markPaid(string $ident): void
    {
        $this->baskets[$ident]['complete'] = true;
    }

    /**
     * What happens when the buyer pays: the basket is complete, and there is a payment for it. `$payment` is the payment as a
     * webhook would carry it (see `InteractsWithTebexWebhooks::tebexProductPaymentFor()`).
     *
     * @param  array<string, mixed>  $payment
     */
    public function pay(string $ident, array $payment): void
    {
        $this->baskets[$ident]['complete'] = true;
        $this->baskets[$ident]['payment'] = $payment;
        $this->payments[$payment['transaction_id']] = $payment;
    }

    /**
     * What Tebex does when the payment is refunded in its own panel.
     */
    public function refundInPanel(string $transaction, int $status = 2): void
    {
        $this->payments[$transaction]['status'] = ['id' => $status, 'description' => $status === 2 ? 'Refund' : 'Refund Pending'];
    }

    /**
     * The calls made, by action.
     *
     * @return list<array{method: string, path: string, url: string, body: array<string, mixed>, headers: array<string, mixed>}>
     */
    public function calls(string $action): array
    {
        return array_values(array_filter($this->requests, fn (array $request) => $this->actionOf($request['method'], $request['path']) === $action));
    }

    public function respond(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        $action = $this->actionOf($method, $path);
        // As it went over the wire: an object the client built is an array here, as it is to Tebex.
        $body = (array) json_decode((string) json_encode($request->data()), true);

        $this->requests[] = ['method' => $method, 'path' => $path, 'url' => $request->url(), 'body' => $body, 'headers' => $request->headers()];

        if ($this->whenAsked !== null && $action !== null) {
            ($this->whenAsked)($action);
        }

        if ($action !== null && isset($this->failures[$action]) && $this->failures[$action]['times'] > 0) {
            $this->failures[$action]['times']--;

            return Http::response(['type' => 'Failure', 'title' => 'Failure on purpose', 'detail' => $this->failures[$action]['detail'] ?? "{$action} failed", 'status' => $this->failures[$action]['status']], $this->failures[$action]['status']);
        }

        return match ($action) {
            'checkout' => $this->checkout($body),
            'get' => $this->show($this->identIn($path)),
            'payment' => $this->payment(basename($path)),
            'refund' => $this->refund(basename(dirname($path))),
            'remove' => $this->remove($this->identIn($path), (int) basename($path)),
            default => Http::response(['type' => 'Not Found', 'title' => 'Resource not found', 'status' => 404], 404),
        };
    }

    private function actionOf(string $method, string $path): ?string
    {
        return match (true) {
            $method === 'POST' && $path === '/api/checkout' => 'checkout',
            $method === 'GET' && preg_match('#^/api/baskets/[^/]+$#', $path) === 1 => 'get',
            $method === 'GET' && preg_match('#^/api/payments/[^/]+$#', $path) === 1 => 'payment',
            $method === 'POST' && preg_match('#^/api/payments/[^/]+/refund$#', $path) === 1 => 'refund',
            $method === 'DELETE' && preg_match('#^/api/baskets/[^/]+/packages/\d+$#', $path) === 1 => 'remove',
            default => null,
        };
    }

    private function identIn(string $path): string
    {
        preg_match('#/baskets/([^/]+)#', $path, $matches);

        return rawurldecode($matches[1] ?? '');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function checkout(array $body): PromiseInterface
    {
        $ident = 'ckt'.str_pad((string) ++$this->made, 6, '0', STR_PAD_LEFT);
        $rows = [];

        foreach ((array) ($body['items'] ?? []) as $item) {
            $package = (array) ($item['package'] ?? []);

            $rows[] = [
                'id' => 5000 + ++$this->rows,
                'basket' => 800000 + $this->made,
                'package' => 0,
                'quantity' => (int) ($item['qty'] ?? 0),
                'price' => round((float) ($package['price'] ?? 0) + $this->extraPerUnit, 2),
                'meta' => ['name' => $package['name'] ?? null, 'rowprice' => (float) ($package['price'] ?? 0)],
                'custom' => $package['custom'] ?? [],
            ];
        }

        $basket = (array) ($body['basket'] ?? []);

        $this->baskets[$ident] = [
            'ident' => $ident,
            'complete' => false,
            'complete_url' => $basket['complete_url'] ?? null,
            'cancel_url' => $basket['return_url'] ?? null,
            'custom' => $basket['custom'] ?? [],
            'rows' => $rows,
        ];

        return Http::response($this->payload($ident));
    }

    private function show(string $ident): PromiseInterface
    {
        return isset($this->baskets[$ident])
            ? Http::response($this->payload($ident))
            : Http::response(['type' => 'Not Found', 'title' => 'Resource not found', 'status' => 404], 404);
    }

    private function payment(string $transaction): PromiseInterface
    {
        return isset($this->payments[$transaction])
            ? Http::response($this->payments[$transaction])
            : Http::response(['type' => 'Not Found', 'title' => 'Resource not found', 'status' => 404], 404);
    }

    private function refund(string $transaction): PromiseInterface
    {
        if (! isset($this->payments[$transaction])) {
            return Http::response(['type' => 'Not Found', 'title' => 'Resource not found', 'status' => 404], 404);
        }

        if ($this->refundStatus !== 1) {
            $this->refundInPanel($transaction, $this->refundStatus);
        }

        return Http::response($this->payments[$transaction]);
    }

    private function remove(string $ident, int $rowId): PromiseInterface
    {
        if (! isset($this->baskets[$ident])) {
            return Http::response(['type' => 'Not Found', 'title' => 'Resource not found', 'status' => 404], 404);
        }

        if (! $this->stubborn) {
            $this->baskets[$ident]['rows'] = array_values(array_filter($this->baskets[$ident]['rows'], fn (array $row) => $row['id'] !== $rowId));
        }

        return Http::response($this->payload($ident));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $ident): array
    {
        $basket = $this->baskets[$ident];
        $subTotal = array_sum(array_map(fn (array $row) => $row['price'] * $row['quantity'], $basket['rows']));
        $tax = round($subTotal * $this->taxRate, 2);
        $total = round($subTotal + $tax, 2);

        $links = match (true) {
            $basket['complete'] => ['payment' => "{$this->checkoutBase}/payments/{$ident}"],
            $this->withoutCheckoutLink => [],
            default => ['checkout' => "{$this->checkoutBase}/{$ident}"],
        };

        return [
            'ident' => $ident,
            'expire' => now()->addHour()->toIso8601String(),
            'price' => $subTotal,
            'priceDetails' => [
                'fullPrice' => $subTotal,
                'subTotal' => $subTotal,
                'discounts' => [],
                'total' => $total,
                'tax' => $tax,
                'balance' => $total,
                'sales' => [],
                'giftcards' => [],
                'recurring' => false,
                'roundUp' => 0,
            ],
            'complete' => $basket['complete'],
            'payment' => $basket['payment'] ?? null,
            'tax' => $tax,
            'rows' => $basket['rows'],
            'complete_url' => $basket['complete_url'],
            'cancel_url' => $basket['cancel_url'],
            'custom' => $basket['custom'],
            'links' => (object) $links,
        ];
    }
}
