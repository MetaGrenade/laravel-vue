<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DownloadCount;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What staff can do about an order's downloads: give the customer their downloads back after they
 * have used them up, or take access away (and give it back). Each is written to the order's history.
 */
class CommerceOrderDownloadController extends Controller
{
    /**
     * Set every file's download count back to zero, so the customer has their full allowance again.
     */
    public function reset(Request $request, Order $order, DownloadGrant $grant): RedirectResponse
    {
        $this->assertBelongsTo($order, $grant);

        DownloadCount::query()->where('download_grant_id', $grant->id)->update(['downloads' => 0]);

        OrderEvent::record($order, OrderEvent::NOTE, 'Reset the download counts for '.$this->describe($grant), by: $request->user());

        return back()->with('success', 'Downloads reset.');
    }

    /**
     * End the customer's access by hand. A refund in full does this by itself; this is for the cases
     * a refund does not cover.
     */
    public function revoke(Request $request, Order $order, DownloadGrant $grant): RedirectResponse
    {
        $this->assertBelongsTo($order, $grant);

        if ($grant->revoked_at === null) {
            $grant->forceFill(['revoked_at' => now(), 'revoked_reason' => DownloadGrant::BY_STAFF])->save();

            OrderEvent::record($order, OrderEvent::NOTE, 'Revoked access to the downloads for '.$this->describe($grant), by: $request->user());
        }

        return back()->with('success', 'Downloads revoked.');
    }

    public function restore(Request $request, Order $order, DownloadGrant $grant): RedirectResponse
    {
        $this->assertBelongsTo($order, $grant);

        if ($grant->revoked_reason === DownloadGrant::BY_REFUND) {
            return back()->with('error', 'This order was refunded in full, which is why the downloads stopped. They come back by themselves if that refund does not go through.');
        }

        if ($grant->revoked_at !== null) {
            $grant->forceFill(['revoked_at' => null, 'revoked_reason' => null])->save();

            OrderEvent::record($order, OrderEvent::NOTE, 'Restored access to the downloads for '.$this->describe($grant), by: $request->user());
        }

        return back()->with('success', 'Downloads restored.');
    }

    private function assertBelongsTo(Order $order, DownloadGrant $grant): void
    {
        abort_unless($grant->order_id === $order->id, 404);
    }

    private function describe(DownloadGrant $grant): string
    {
        return (string) ($grant->item->description ?? 'an item');
    }
}
