<?php

namespace App\Support\Commerce\Catalogue;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff changes to stock. Every change is written to the movement ledger with who made it
 * and why, next to the movements orders make, so a stock level can always be explained.
 *
 * `quantity` is what can still be sold: orders take stock from it when they are placed and
 * give it back when they are cancelled, so it is the on-hand count less what is spoken for.
 */
class StockAdjuster
{
    /** The most a single count or correction may be, to keep the column well inside its range. */
    public const LIMIT = 1_000_000;

    /**
     * Start tracking stock for a product, or for one of its variants.
     *
     * @throws CatalogueException
     */
    public function track(Product $product, ?ProductVariant $variant, int $quantity, bool $allowBackorder = false, ?User $by = null): InventoryItem
    {
        if ($variant !== null && $variant->product_id !== $product->id) {
            throw new CatalogueException('That variant belongs to a different product.');
        }

        $this->assertWithinLimit($quantity);

        if ($quantity < 0) {
            throw new CatalogueException('Opening stock cannot be negative.', 'quantity');
        }

        return DB::transaction(function () use ($product, $variant, $quantity, $allowBackorder, $by) {
            // Two requests must not both create the row: the table cannot enforce one per product
            // (a missing variant is NULL, and NULLs are never equal), so serialise on the product.
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $exists = InventoryItem::query()
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)
                ->exists();

            if ($exists) {
                throw new CatalogueException('Stock is already tracked for this item.');
            }

            $item = InventoryItem::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'allow_backorder' => $allowBackorder,
            ]);

            if ($quantity !== 0) {
                $this->record($item, $quantity, 'Opening stock', $by);
            }

            return $item;
        });
    }

    /**
     * Set the quantity to a counted amount.
     *
     * @throws CatalogueException
     */
    public function set(InventoryItem $item, int $quantity, ?string $note = null, ?User $by = null): InventoryItem
    {
        $this->assertWithinLimit($quantity);

        return DB::transaction(function () use ($item, $quantity, $note, $by) {
            $locked = $this->lock($item);

            return $this->apply($locked, $quantity - $locked->quantity, $note, $by);
        });
    }

    /**
     * Add stock (a delivery) or take some away (damage, a correction).
     *
     * @throws CatalogueException
     */
    public function change(InventoryItem $item, int $delta, ?string $note = null, ?User $by = null): InventoryItem
    {
        $this->assertWithinLimit($delta);

        return DB::transaction(fn () => $this->apply($this->lock($item), $delta, $note, $by));
    }

    /**
     * Stop tracking: the item is always available again. Its movement history goes with it.
     */
    public function untrack(InventoryItem $item): void
    {
        $item->delete();
    }

    private function lock(InventoryItem $item): InventoryItem
    {
        // Orders change the same row with atomic updates; holding the row while the new level
        // is worked out means a sale cannot slip in between the read and the write.
        return InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
    }

    private function apply(InventoryItem $locked, int $delta, ?string $note, ?User $by): InventoryItem
    {
        if ($delta === 0) {
            return $locked;
        }

        $quantity = $locked->quantity + $delta;

        if ($quantity < 0 && ! $locked->allow_backorder) {
            throw new CatalogueException("There are only {$locked->quantity} available, so that would take stock below zero.", 'quantity');
        }

        $locked->forceFill(['quantity' => $quantity])->save();

        $this->record($locked, $delta, $note, $by);

        return $locked;
    }

    private function record(InventoryItem $item, int $delta, ?string $note, ?User $by): void
    {
        InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'user_id' => $by?->id,
            'delta' => $delta,
            'reason' => InventoryMovement::ADJUSTMENT,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
        ]);
    }

    private function assertWithinLimit(int $amount): void
    {
        if (abs($amount) > self::LIMIT) {
            throw new CatalogueException('That is more than the shop can track in one go (1,000,000).', 'quantity');
        }
    }
}
