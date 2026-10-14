<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Digital\LicenceKeys;
use App\Support\Commerce\OrderLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What staff can do about the licence keys of an order: issue the ones it is still owed, and take a key
 * back from the customer (with or without giving them another). Each is written to the order's history,
 * which never contains a key.
 */
class CommerceOrderLicenceKeyController extends Controller
{
    public function __construct(
        private readonly LicenceKeys $keys,
        private readonly OrderLifecycle $lifecycle,
    ) {}

    /**
     * Give the order the keys it is still owed, from the pool as it is now.
     */
    public function issue(Request $request, Order $order): RedirectResponse
    {
        if ($order->payment_status === OrderPaymentStatus::Refunded || ! $order->isPaid()) {
            return back()->with('error', 'Only a paid order that was not refunded in full is owed keys.');
        }

        $issued = $this->lifecycle->issueKeys($order, $request->user());

        return $issued > 0
            ? back()->with('success', $issued.' licence '.Str::plural('key', $issued).' issued.')
            : back()->with('error', 'There are no unused keys to issue. Add keys to the product first.');
    }

    /**
     * Take the key away from the customer, so the line is owed a key again.
     */
    public function withdraw(Request $request, Order $order, LicenceKey $key): RedirectResponse
    {
        $this->assertBelongsTo($order, $key);

        try {
            $this->keys->withdraw($key, $request->user());
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Key withdrawn. The customer is owed another: it is issued when there is one in the pool.');
    }

    /**
     * Take the key away and give the customer another from the pool straight away.
     */
    public function replace(Request $request, Order $order, LicenceKey $key): RedirectResponse
    {
        $this->assertBelongsTo($order, $key);

        try {
            $this->keys->withdraw($key, $request->user(), replace: true);
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Key replaced.');
    }

    private function assertBelongsTo(Order $order, LicenceKey $key): void
    {
        abort_unless($key->orderItem !== null && $key->orderItem->order_id === $order->id, 404);
    }
}
