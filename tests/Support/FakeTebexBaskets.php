<?php

namespace Tests\Support;

use App\Models\TebexPackage;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Tebex's basket API as a small stateful stand-in, so a test can follow a basket from being made to being paid or
 * emptied without scripting each answer.
 *
 * It answers in the shape of Tebex's OpenAPI schema. Prices come from the packages in the test database (the ones a
 * sync would have copied), and a package is found by its `ident` or its id. Everything it was asked is kept in
 * {@see self::$requests}, and a failure can be made to happen once or more with {@see self::failOn()}.
 */
class FakeTebexBaskets
{
    /** @var array<string, array<string, mixed>> Baskets by ident. */
    public array $baskets = [];

    /** @var list<array{method: string, path: string, url: string, body: array<string, mixed>, headers: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, array{status: int, times: int}> What to fail with, by action. */
    private array $failures = [];

    private int $made = 0;

    /** The sales tax Tebex quotes, as a share of the price before tax (0.2 is 20%). */
    public float $taxRate = 0.0;

    public string $currency = 'USD';

    /** When true, taking a package out of a basket does nothing (Tebex refusing to empty it). */
    public bool $stubborn = false;

    /** @var array<int, float> What Tebex charges for a package (by its id) when that is not the copy's price. */
    public array $prices = [];

    /** When true, Tebex will not take a package into a basket nobody has signed in to or named a user for (a game store). */
    public bool $requiresIdentity = false;

    /** @var list<string> Usernames Tebex does not recognise (the basket comes back with no username id). */
    public array $unknownUsernames = [];

    /** @var list<array{name: string, url: string}> The ways Tebex offers to sign in. */
    public array $signInOptions = [['name' => 'Steam', 'url' => 'https://auth.tebex.test/steam']];

    /**
     * Called with the action ('add', 'get'...) as Tebex receives a call and before it does anything, so a test can have
     * something else happen in that moment (a second request arriving while the first is part way through).
     *
     * @var (Closure(string): void)|null
     */
    public ?Closure $whenAsked = null;

    /** When true, Tebex answers a basket with packages in it but no place to pay (links.checkout missing). */
    public bool $withoutCheckoutLink = false;

    /** Where the checkout links Tebex gives are (the address of the page a basket is paid on). */
    public string $checkoutBase = 'https://pay.tebex.io';

    /**
     * Fail the next `$times` calls of an action ('create', 'add', 'remove', 'get') with this status.
     */
    public function failOn(string $action, int $status, int $times = 1): self
    {
        $this->failures[$action] = ['status' => $status, 'times' => $times];

        return $this;
    }

    /**
     * What Tebex does when the buyer has signed in and come back: the basket belongs to someone it knows.
     */
    public function authorise(string $ident, int $usernameId = 76561198000000001, ?string $username = 'ProGamer'): void
    {
        $this->baskets[$ident]['username_id'] = $usernameId;
        $this->baskets[$ident]['username'] = $username;
    }

    /**
     * What Tebex does when the customer has paid: the basket is complete and offers a payment, not a way to pay.
     */
    public function markPaid(string $ident): void
    {
        $this->baskets[$ident]['complete'] = true;
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

            return Http::response(['title' => 'Failure on purpose', 'detail' => "{$action} failed", 'status' => $this->failures[$action]['status']], $this->failures[$action]['status']);
        }

        return match ($action) {
            'create' => $this->create($body),
            'add' => $this->add($this->identIn($path), $body),
            'remove' => $this->remove($this->identIn($path), $body),
            'get' => $this->show($this->identIn($path)),
            'auth' => Http::response($this->signInOptions),
            default => Http::response(['title' => 'Not found', 'status' => 404], 404),
        };
    }

    private function actionOf(string $method, string $path): ?string
    {
        return match (true) {
            $method === 'POST' && preg_match('#^/api/accounts/[^/]+/baskets$#', $path) === 1 => 'create',
            $method === 'POST' && preg_match('#^/api/baskets/[^/]+/packages$#', $path) === 1 => 'add',
            $method === 'POST' && preg_match('#^/api/baskets/[^/]+/packages/remove$#', $path) === 1 => 'remove',
            $method === 'GET' && preg_match('#^/api/accounts/[^/]+/baskets/[^/]+/auth$#', $path) === 1 => 'auth',
            $method === 'GET' && preg_match('#^/api/accounts/[^/]+/baskets/[^/]+$#', $path) === 1 => 'get',
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
    private function create(array $body): PromiseInterface
    {
        $ident = 'bsk'.str_pad((string) ++$this->made, 6, '0', STR_PAD_LEFT);

        $this->baskets[$ident] = [
            'id' => (string) (900000 + $this->made),
            'ident' => $ident,
            'complete' => false,
            'complete_url' => $body['complete_url'] ?? null,
            'cancel_url' => $body['cancel_url'] ?? null,
            'complete_auto_redirect' => (bool) ($body['complete_auto_redirect'] ?? false),
            'ip' => $body['ip_address'] ?? null,
            'custom' => $body['custom'] ?? [],
            'username' => $body['username'] ?? null,
            'username_id' => isset($body['username']) && ! in_array($body['username'], $this->unknownUsernames, true) ? 700000 + (crc32((string) $body['username']) % 99999) : 0,
            'lines' => [],
        ];

        return Http::response(['data' => $this->payload($ident)]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function add(string $ident, array $body): PromiseInterface
    {
        if (! isset($this->baskets[$ident])) {
            return Http::response(['title' => 'No such basket', 'status' => 404], 404);
        }

        if ($this->requiresIdentity && $this->baskets[$ident]['username_id'] === 0) {
            return Http::response(['title' => 'Forbidden', 'detail' => 'The basket has not been authorised.', 'status' => 403], 403);
        }

        $reference = (string) ($body['package_id'] ?? '');
        $package = TebexPackage::query()->where('ident', $reference)->orWhere('tebex_id', ctype_digit($reference) ? (int) $reference : 0)->first();

        if ($package === null) {
            return Http::response(['title' => 'Bad request', 'detail' => 'The package ident is not one in this store.', 'status' => 400], 400);
        }

        $quantity = (int) ($body['quantity'] ?? 1);
        $line = &$this->baskets[$ident]['lines'][$package->tebex_id];
        $line = ['package' => $package, 'quantity' => ($line['quantity'] ?? 0) + $quantity, 'variable_data' => $body['variable_data'] ?? null];
        unset($line);

        return Http::response(['data' => $this->payload($ident)]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function remove(string $ident, array $body): PromiseInterface
    {
        if (! isset($this->baskets[$ident])) {
            return Http::response(['title' => 'No such basket', 'status' => 404], 404);
        }

        if (! $this->stubborn) {
            unset($this->baskets[$ident]['lines'][(int) ($body['package_id'] ?? 0)]);
        }

        return Http::response(['data' => $this->payload($ident)]);
    }

    private function show(string $ident): PromiseInterface
    {
        return isset($this->baskets[$ident])
            ? Http::response(['data' => $this->payload($ident)])
            : Http::response(['title' => 'No such basket', 'status' => 404], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $ident): array
    {
        $basket = $this->baskets[$ident];
        $base = 0.0;
        $packages = [];

        foreach ($basket['lines'] as $line) {
            /** @var TebexPackage $package */
            $package = $line['package'];
            $price = $this->prices[$package->tebex_id] ?? (float) $package->base_price;
            $base += $price * $line['quantity'];

            $packages[] = [
                'id' => $package->tebex_id,
                'name' => $package->name,
                'slug' => $package->slug,
                'description' => '',
                'image' => $package->image_url,
                'type' => $package->type,
                'is_recurring' => $package->isSubscription(),
                'in_basket' => ['quantity' => $line['quantity'], 'price' => $price, 'gift_username_id' => '', 'gift_username' => ''],
            ];
        }

        $tax = round($base * $this->taxRate, 2);

        return [
            'id' => $basket['id'],
            'ident' => $ident,
            'complete' => $basket['complete'],
            'email' => null,
            'username' => $basket['username'],
            'coupons' => [],
            'giftcards' => [],
            'creator_code' => '',
            'cancel_url' => $basket['cancel_url'],
            'complete_url' => $basket['complete_url'],
            'complete_auto_redirect' => $basket['complete_auto_redirect'],
            'country' => 'US',
            'ip' => $basket['ip'],
            'username_id' => $basket['username_id'],
            'base_price' => $base,
            'sales_tax' => $tax,
            'total_price' => $base + $tax,
            'currency' => $this->currency,
            'packages' => $packages,
            'custom' => $basket['custom'],
            'links' => match (true) {
                $basket['complete'] => ['payment' => "https://pay.tebex.io/{$ident}/payment"],
                $this->withoutCheckoutLink && $basket['lines'] !== [] => [],
                default => ['checkout' => "{$this->checkoutBase}/{$ident}"],
            },
        ];
    }
}
