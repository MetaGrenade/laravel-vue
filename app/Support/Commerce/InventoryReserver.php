<?php

namespace App\Support\Commerce;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Holds stock for an order while it waits for payment, and gives it back if the
 * order is cancelled. Every change is written to the movement ledger.
 *
 * A product with no inventory row is not tracked and always available. Call
 * these inside the transaction that places or changes the order.
 */
class InventoryReserver
{
    /**
     * Take stock for every line. With $enforce the call fails (and the caller's
     * transaction should roll back) when a tracked line does not have enough.
     *
     * @throws InsufficientStockException
     */
    public function reserve(Order $order, bool $enforce = true): void
    {
        $short = [];

        foreach ($order->items as $item) {
            $inventory = $this->inventoryFor($item);

            if ($inventory === null) {
                continue;
            }

            $query = InventoryItem::query()->whereKey($inventory->id);

            if ($enforce) {
                // One conditional UPDATE, so two buyers racing for the last unit
                // cannot both succeed whatever the database or isolation level.
                $query->where(function ($query) use ($item) {
                    $query->where('quantity', '>=', $item->quantity)
                        ->orWhere('allow_backorder', true);
                });
            }

            if ($query->decrement('quantity', $item->quantity) === 0) {
                $short[] = $item->description ?: 'an item';

                continue;
            }

            InventoryMovement::create([
                'inventory_item_id' => $inventory->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'delta' => -$item->quantity,
                'reason' => InventoryMovement::RESERVATION,
            ]);
        }

        if ($short !== []) {
            throw new InsufficientStockException($short);
        }
    }

    /**
     * Return whatever the order still holds. Safe to call more than once: only
     * the net quantity still reserved is given back.
     */
    public function release(Order $order, ?string $note = null): void
    {
        $this->giveBack($order, InventoryMovement::RELEASE, $note);
    }

    /**
     * Put a paid order's stock back on the shelf because it was refunded in full.
     * Like {@see self::release()}, only what the order still holds is returned, so
     * repeating it (or releasing afterwards) cannot add stock twice.
     */
    public function restock(Order $order, ?string $note = null): void
    {
        $this->giveBack($order, InventoryMovement::RESTOCK, $note);
    }

    /**
     * Take again the stock that {@see self::restock()} returned, because the refund that
     * returned it did not hold and the order is owed to the customer once more. Like a
     * late payment it may leave stock negative: the order is real, so the goods are spoken for.
     *
     * Only stock that was actually restocked is taken, and only if the order does not already
     * hold it, so repeating this cannot take it twice.
     */
    public function takeBack(Order $order, ?string $note = null): void
    {
        $groups = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->whereIn('reason', [InventoryMovement::RESERVATION, InventoryMovement::RELEASE, InventoryMovement::RESTOCK])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (InventoryMovement $movement) => $movement->inventory_item_id.':'.$movement->order_item_id);

        foreach ($groups as $movements) {
            if (! $movements->contains('reason', InventoryMovement::RESTOCK) || -$movements->sum('delta') > 0) {
                continue;
            }

            $reservation = $movements->firstWhere('reason', InventoryMovement::RESERVATION);

            if ($reservation === null || $reservation->delta >= 0) {
                continue;
            }

            $quantity = -$reservation->delta;

            InventoryItem::query()->whereKey($reservation->inventory_item_id)->decrement('quantity', $quantity);

            InventoryMovement::create([
                'inventory_item_id' => $reservation->inventory_item_id,
                'order_id' => $order->id,
                'order_item_id' => $reservation->order_item_id,
                'delta' => -$quantity,
                'reason' => InventoryMovement::RESERVATION,
                'note' => $note,
            ]);
        }
    }

    private function giveBack(Order $order, string $reason, ?string $note): void
    {
        $held = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->whereIn('reason', [InventoryMovement::RESERVATION, InventoryMovement::RELEASE, InventoryMovement::RESTOCK])
            ->get()
            ->groupBy(fn (InventoryMovement $movement) => $movement->inventory_item_id.':'.$movement->order_item_id);

        foreach ($held as $movements) {
            $outstanding = -$movements->sum('delta');

            if ($outstanding <= 0) {
                continue;
            }

            /** @var InventoryMovement $first */
            $first = $movements->first();

            InventoryItem::query()->whereKey($first->inventory_item_id)->update([
                'quantity' => DB::raw('quantity + '.(int) $outstanding),
            ]);

            InventoryMovement::create([
                'inventory_item_id' => $first->inventory_item_id,
                'order_id' => $order->id,
                'order_item_id' => $first->order_item_id,
                'delta' => $outstanding,
                'reason' => $reason,
                'note' => $note,
            ]);
        }
    }

    private function inventoryFor(OrderItem $item): ?InventoryItem
    {
        if ($item->product_variant_id !== null) {
            $variantStock = InventoryItem::query()
                ->where('product_variant_id', $item->product_variant_id)
                ->first();

            if ($variantStock !== null) {
                return $variantStock;
            }
        }

        if ($item->product_id === null) {
            return null;
        }

        return InventoryItem::query()
            ->where('product_id', $item->product_id)
            ->whereNull('product_variant_id')
            ->first();
    }
}
