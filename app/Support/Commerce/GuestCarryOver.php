<?php

namespace App\Support\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\User;
use App\Support\Ownership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What a shopper did before they signed in, brought into their account.
 *
 * **The cart.** A guest's cart is tied to their browser session, and signing in starts a new session, so the
 * cart they had filled in would be left behind. At the moment of signing in (while the old session id is still
 * the browser's) it is merged into the cart the account already has, or becomes the account's cart.
 *
 * **Orders.** A guest order is reachable only through the link in its receipt. Once an account's email address
 * is verified, the guest orders placed with that address are linked to the account, so the purchases, keys and
 * downloads are found with the rest. Verification is the proof that the person owns the address: an unverified
 * account claims nothing, or anyone could take an order by signing up with someone else's email.
 */
class GuestCarryOver
{
    /** Where the browser's guest session id is kept while it signs in. */
    private const SESSION_KEY = 'commerce.guest_session';

    /**
     * Note which session the browser is using as a guest. Call it before anything signs the person in: signing in
     * (`Auth::attempt()` and `Auth::login()` alike) gives the browser a new session id at once, and the guest's
     * cart is tied to the old one. A sign-in that takes two steps (a password, then a code) keeps the first note.
     */
    public function remember(Request $request): void
    {
        if (! $request->session()->has(self::SESSION_KEY)) {
            $request->session()->put(self::SESSION_KEY, $request->session()->getId());
        }
    }

    /**
     * Everything that follows the shopper into their account once they are signed in.
     */
    public function afterSignIn(User $user, Request $request): void
    {
        $guestSession = (string) $request->session()->pull(self::SESSION_KEY, $request->session()->getId());

        $this->mergeCart($user, $guestSession);
        $this->claimOrders($user);
    }

    /**
     * Merge the cart this browser session had as a guest into the account's cart.
     *
     * A line the account's cart already has is added to (up to the most one line may hold); any other line
     * moves over. The account's discount code is kept, or the guest's is taken when the account has none. The
     * guest cart is then gone, so there is never a second cart to choose between.
     */
    public function mergeCart(User $user, string $guestSessionId): ?Cart
    {
        if ($guestSessionId === '') {
            return null;
        }

        return DB::transaction(function () use ($user, $guestSessionId) {
            $guestCarts = Cart::query()
                ->where('status', CartManager::OPEN)
                ->whereNull('user_id')
                ->where('session_id', $guestSessionId)
                ->with('items')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($guestCarts->isEmpty()) {
                return null;
            }

            $mine = Cart::query()
                ->where('status', CartManager::OPEN)
                ->where('user_id', $user->id)
                ->with('items')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            foreach ($guestCarts as $guest) {
                if ($guest->items->isEmpty()) {
                    // Nothing to carry over: an empty guest cart is just discarded.
                    $guest->delete();

                    continue;
                }

                if ($mine === null) {
                    // The account has no cart yet: the guest's simply becomes it.
                    $guest->forceFill(['user_id' => $user->id])->save();
                    $mine = $guest;

                    continue;
                }

                $this->mergeInto($mine, $guest);
            }

            return $mine?->refresh();
        });
    }

    /**
     * Link the guest orders placed with this account's email address to the account. Returns how many were linked.
     *
     * Only orders with no owner and no user are touched, only once the account's email is verified, and the
     * addresses are compared without regard to case. It is safe to run as often as you like.
     */
    public function claimOrders(User $user): int
    {
        if (! $user->hasVerifiedEmail() || blank($user->email)) {
            return 0;
        }

        $owner = Ownership::newRecordOwnerFor($user);
        $claimed = 0;

        $ids = Order::query()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('user_id')
            ->where(DB::raw('lower(customer_email)'), mb_strtolower($user->email))
            ->pluck('id');

        foreach ($ids as $id) {
            $claimed += DB::transaction(function () use ($id, $user, $owner): int {
                // Locked and checked again: two requests (a login and a verification) must not both claim it.
                $order = Order::query()->lockForUpdate()->find($id);

                if ($order === null || $order->owner_type !== null || $order->owner_id !== null || $order->user_id !== null) {
                    return 0;
                }

                $order->assignOwner($owner);
                $order->user_id = $user->id;
                $order->save();

                // A payment belongs to whoever owns its order.
                Payment::query()->where('order_id', $order->id)->update([
                    'owner_type' => $order->owner_type,
                    'owner_id' => $order->owner_id,
                ]);

                OrderEvent::record($order, OrderEvent::CLAIMED, "Linked to the account of {$user->nickname} (verified email {$user->email})", by: $user);

                return 1;
            });
        }

        return $claimed;
    }

    private function mergeInto(Cart $mine, Cart $guest): void
    {
        foreach ($guest->items as $line) {
            $existing = $mine->items->first(fn (CartItem $item) => $item->product_id === $line->product_id
                && $item->product_variant_id === $line->product_variant_id);

            if ($existing !== null) {
                $most = (int) config('commerce.checkout.max_quantity', 20);

                CartManager::updateQuantity($existing, min($most, $existing->quantity + $line->quantity));
                $line->delete();

                continue;
            }

            $line->forceFill(['cart_id' => $mine->id])->save();
        }

        if ($mine->coupon_id === null && $guest->coupon_id !== null) {
            $mine->forceFill(['coupon_id' => $guest->coupon_id])->save();
        }

        $guest->delete();

        $mine->load('items');
        CartManager::recalculate($mine);
    }
}
