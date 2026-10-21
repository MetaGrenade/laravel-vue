<?php

namespace App\Payments\Tebex;

use App\Models\Order;
use App\Models\OrderItem;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Puts an order's packages into its Tebex basket, and reports what Tebex quoted for them.
 *
 * Used when a checkout starts (a store that asks nothing, or only a username, has its packages added at once) and when
 * a buyer comes back from signing in (a store that makes them sign in cannot be filled before they have).
 */
class TebexBasketBuilder
{
    public function __construct(private readonly TebexClient $client) {}

    /**
     * Run some work on a basket while nobody else changes it.
     *
     * Putting a package in a basket adds to what Tebex holds, so two requests that both read the basket as empty and both
     * filled it would double every package, and the buyer would be sent to pay twice the order. The work has to read the
     * basket itself, after the lock is taken, so that the request that waited sees what the other did and leaves it alone.
     * A request that cannot get the basket in time gives up without touching it.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws TebexBasketBusy When another request still has the basket after the time allowed.
     */
    public function exclusively(string $ident, Closure $work): mixed
    {
        // Held for two minutes at most, in case a request dies; the work itself takes a few calls to Tebex.
        $lock = Cache::lock('tebex:basket:'.$ident, 120);

        try {
            $lock->block(max(0, (int) config('tebex.basket.lock_wait_seconds', 10)));
        } catch (LockTimeoutException) {
            throw new TebexBasketBusy('The basket is being changed by another request.');
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * Make the basket hold exactly what the order holds: what is already right is left, and anything else is taken out
     * and put in again. Safe to repeat, which matters because a buyer can come back through the same link twice.
     *
     * Not safe to run twice at once on one basket: wrap the reading of the basket and this in {@see self::exclusively()}
     * wherever two requests could reach it together.
     *
     * @throws TebexException
     */
    public function fill(Order $order, TebexBasket $basket): TebexBasket
    {
        $lines = $this->lines($order);

        $wanted = $lines->mapWithKeys(fn (OrderItem $item) => [(int) $item->tebexPackage?->tebex_id => $item->quantity])->all();
        $held = collect($basket->packages)->mapWithKeys(fn (array $package) => [$package['id'] => $package['quantity']])->all();

        ksort($wanted);
        ksort($held);

        if ($wanted === $held) {
            return $basket;
        }

        foreach ($basket->packages as $package) {
            $basket = $this->client->removePackage($basket->ident, (string) $package['id']);
        }

        foreach ($lines as $item) {
            $basket = $this->client->addPackage($basket->ident, (string) $item->tebexPackage?->ident, $item->quantity, $this->variableData($basket));
        }

        return $basket;
    }

    /**
     * What Tebex quoted for the basket, for the payment to keep, and whether it is not what the shop quoted.
     *
     * @return array{quoted: array<string, string>, price_differs: bool}
     */
    public function quote(Order $order, TebexBasket $basket): array
    {
        $differs = $basket->basePrice->toDecimal() !== number_format((float) $order->subtotal, 2, '.', '');

        if ($differs) {
            // The shop priced the order from its copy of the catalogue, and Tebex's price has moved since. The
            // customer pays what Tebex charges; this is for whoever reconciles the payment.
            Log::warning('Tebex quoted a different price than the shop did for an order.', [
                'order' => $order->number,
                'shop' => $order->subtotal,
                'tebex' => $basket->basePrice->toDecimal(),
            ]);
        }

        return [
            'quoted' => [
                'currency' => $basket->currency,
                'base_price' => $basket->basePrice->toDecimal(),
                'sales_tax' => $basket->salesTax->toDecimal(),
                'total_price' => $basket->totalPrice->toDecimal(),
            ],
            'price_differs' => $differs,
        ];
    }

    /**
     * Who the buyer is as far as Tebex knows (kept on the payment, and shown to staff), or null for a store that does
     * not identify its buyers.
     *
     * @return array{username: string|null, username_id: int}|null
     */
    public function buyer(TebexBasket $basket): ?array
    {
        return $basket->usernameId === null ? null : ['username' => $basket->username, 'username_id' => $basket->usernameId];
    }

    /**
     * What a package needs to be delivered to the right person. For an identified buyer that is their id at Tebex, which
     * Tebex's documentation names for packages that run a command on a game server. Which of the packages those are is
     * not in the catalogue, so every package carries it. (Confirm against a real store: M2b.)
     *
     * @return array<string, int>|null
     */
    private function variableData(TebexBasket $basket): ?array
    {
        return $basket->usernameId === null ? null : ['username_id' => $basket->usernameId];
    }

    /**
     * @return Collection<int, OrderItem>
     */
    private function lines(Order $order): Collection
    {
        $order->loadMissing('items.tebexPackage');

        // In the order they were placed in (a relation has none of its own), so a basket is always built the same way.
        return $order->items->sortBy('id')->values();
    }
}
