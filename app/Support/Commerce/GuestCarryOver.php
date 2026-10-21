<?php

namespace App\Support\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\ProviderSubscription;
use App\Models\User;
use App\Support\Entitlements\OrderEntitlements;
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
    /** Where the guest session ids the browser has used while signing in are kept. */
    private const SESSION_KEY = 'commerce.guest_sessions';

    /**
     * Note which session the browser is using as a guest. Call it before anything signs the person in: signing in
     * (`Auth::attempt()` and `Auth::login()` alike) gives the browser a new session id at once, and the guest's
     * cart is tied to the old one. A sign-in that takes two steps (a password, then a code) goes through two
     * sessions, and the shopper may fill a cart in the second while the code is awaited, so each one is noted.
     */
    public function remember(Request $request): void
    {
        $sessions = $this->remembered($request);
        $current = $request->session()->getId();

        if (! in_array($current, $sessions, true)) {
            $request->session()->put(self::SESSION_KEY, [...$sessions, $current]);
        }
    }

    /**
     * Everything that follows the shopper into their account once they are signed in.
     */
    public function afterSignIn(User $user, Request $request): void
    {
        $sessions = $this->remembered($request);
        $request->session()->forget(self::SESSION_KEY);

        $this->mergeCart($user, $sessions !== [] ? $sessions : [$request->session()->getId()]);
        $this->claimOrders($user);
    }

    /**
     * Merge the carts this browser had as a guest into the account's cart.
     *
     * A line the account's cart already has is added to (up to the most one line may hold); any other line
     * moves over. The account's discount code is kept, or the guest's is taken when the account has none. A guest cart
     * that cannot share a cart with the account's (products against packages, or packages in another currency) is not
     * merged: the account's cart stays whole and the shopper is told. The guest cart is then gone, so there is never a second cart to choose between, and any checkout that was
     * started from it carries on from the account's cart.
     *
     * @param  string|list<string>  $guestSessionIds  The session (or sessions) the browser was a guest in.
     */
    public function mergeCart(User $user, string|array $guestSessionIds): ?Cart
    {
        $sessions = array_values(array_filter((array) $guestSessionIds, fn (string $id) => $id !== ''));

        if ($sessions === []) {
            return null;
        }

        return DB::transaction(function () use ($user, $sessions) {
            $guestCarts = Cart::query()
                ->where('status', CartManager::OPEN)
                ->whereNull('user_id')
                ->whereIn('session_id', $sessions)
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
                // An empty cart with nothing started from it is just discarded. One that a checkout was started
                // from is not: that checkout can still be paid, and needs a cart to be found through.
                if ($guest->items->isEmpty() && ! Order::query()->where('cart_id', $guest->id)->exists()) {
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

                // And so does a recurring payment the order started.
                ProviderSubscription::query()->where('order_id', $order->id)->update([
                    'owner_type' => $order->owner_type,
                    'owner_id' => $order->owner_id,
                ]);

                // And the entitlements it gave, which are of no use to anyone until it has an owner.
                app(OrderEntitlements::class)->claim($order);

                OrderEvent::record($order, OrderEvent::CLAIMED, "Linked to the account of {$user->nickname} (verified email {$user->email})", by: $user);

                return 1;
            });
        }

        return $claimed;
    }

    /**
     * @return list<string>
     */
    private function remembered(Request $request): array
    {
        return array_values(array_filter((array) $request->session()->get(self::SESSION_KEY, []), is_string(...)));
    }

    private function mergeInto(Cart $mine, Cart $guest): void
    {
        if ($this->canShareACart($mine, $guest)) {
            $this->moveLines($mine, $guest);

            if ($mine->coupon_id === null && $guest->coupon_id !== null) {
                $mine->forceFill(['coupon_id' => $guest->coupon_id])->save();
            }

            if ($mine->gift_card_id === null && $guest->gift_card_id !== null) {
                $mine->forceFill(['gift_card_id' => $guest->gift_card_id])->save();
            }
        } else {
            // The account's cart stays as it is, whole, and what was added before signing in is left out (with its
            // code): the same refusal adding it to this cart would have got, but there is no one to tell at that
            // moment, so the shopper is told now.
            session()->flash('warning', 'Some of what you added before signing in could not be put in your cart, because it cannot be bought together with what is already in it. Check out or empty your cart first, then add it again.');
        }

        // A checkout started from the guest cart can still be paid. It is found through the cart it came from
        // (starting again closes it first; paying it takes its lines out), and deleting the cart would cut that
        // link, so the orders move to the cart that replaces it.
        Order::query()->where('cart_id', $guest->id)->update(['cart_id' => $mine->id]);

        $guest->delete();

        $mine->load('items');
        CartManager::recalculate($mine);
    }

    /**
     * Whether two carts can become one that checkout will take: products and packages are bought separately, and the
     * packages in one cart are in one currency. (A cart with nothing in it clashes with nothing.)
     */
    private function canShareACart(Cart $mine, Cart $guest): bool
    {
        $kinds = [CartManager::kind($mine), CartManager::kind($guest)];

        if (in_array(CartManager::EMPTY, $kinds, true)) {
            return true;
        }

        if ($kinds[0] !== $kinds[1]) {
            return false;
        }

        return $kinds[0] !== CartManager::PACKAGES || strtoupper($mine->currency) === strtoupper($guest->currency);
    }

    /**
     * Add the guest's lines to the cart: a line the cart already has is added to (up to the most it may hold), any
     * other line moves over.
     */
    private function moveLines(Cart $mine, Cart $guest): void
    {
        foreach ($guest->items as $line) {
            $existing = $mine->items->first(fn (CartItem $item) => $item->product_id === $line->product_id
                && $item->product_variant_id === $line->product_variant_id
                && $item->tebex_package_id === $line->tebex_package_id);

            if ($existing !== null) {
                CartManager::updateQuantity($existing, $existing->quantity + $line->quantity);
                $line->delete();

                continue;
            }

            $line->forceFill(['cart_id' => $mine->id])->save();
        }
    }
}
