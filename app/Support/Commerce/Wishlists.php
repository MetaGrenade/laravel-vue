<?php

namespace App\Support\Commerce;

use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The products a signed-in customer has saved to come back to.
 *
 * Saving is quiet and repeatable: saving a product twice is the same as saving it once. A product that is
 * switched off stays on the list (it may come back) but is not shown or counted while it is off.
 */
class Wishlists
{
    /**
     * Save a product. Returns whether it was newly saved (false when it was already there).
     *
     * @throws WishlistException When the list is full.
     */
    public function add(User $user, Product $product): bool
    {
        $max = max(1, (int) config('commerce.wishlist.max_items', 200));

        return DB::transaction(function () use ($user, $product, $max): bool {
            // One save at a time per customer. The count below is only the truth while nobody else is saving,
            // or two requests for different products could both see room for one more and both go in. What is
            // locked is the customer's own row, which always exists: locking the range of their entries
            // instead would, on an empty list, let both requests lock the same gap and then deadlock.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Read as it is now (a locking read), not as it was when this transaction began.
            $saved = WishlistItem::query()->where('user_id', $user->id)->lockForUpdate()->pluck('product_id')->map(fn ($id) => (int) $id);

            if ($saved->contains($product->id)) {
                return false;
            }

            if ($saved->count() >= $max) {
                throw new WishlistException("Your wishlist is full ({$max} products). Remove something to save more.");
            }

            return WishlistItem::query()->insertOrIgnore([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]) > 0;
        });
    }

    public function remove(User $user, Product $product): void
    {
        WishlistItem::query()->where('user_id', $user->id)->where('product_id', $product->id)->delete();
    }

    /**
     * Which of these products the user has saved.
     *
     * @param  iterable<int>  $productIds
     * @return list<int>
     */
    public function among(?User $user, iterable $productIds): array
    {
        $productIds = collect($productIds)->unique()->values();

        if ($user === null || $productIds->isEmpty()) {
            return [];
        }

        return WishlistItem::query()
            ->where('user_id', $user->id)
            ->whereIn('product_id', $productIds->all())
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * How many saved products are on sale now.
     */
    public function count(User $user): int
    {
        return $this->saved($user)->count();
    }

    /**
     * The saved products that are on sale, most recently saved first, ready to be shown as cards.
     *
     * @return Builder<Product>
     */
    public function products(User $user): Builder
    {
        return $this->saved($user)->orderByDesc('wishlist_items.id');
    }

    /**
     * @return Builder<Product>
     */
    private function saved(User $user): Builder
    {
        return StorefrontProducts::query()
            ->join('wishlist_items', 'wishlist_items.product_id', '=', 'products.id')
            ->where('wishlist_items.user_id', $user->id);
    }
}
