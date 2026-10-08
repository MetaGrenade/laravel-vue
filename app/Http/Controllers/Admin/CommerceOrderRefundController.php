<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundOrderRequest;
use App\Models\Order;
use App\Models\Refund;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundException;
use App\Support\Commerce\RefundRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Sends money back to a customer. Needs its own permission: unlike editing an order,
 * it cannot be undone.
 */
class CommerceOrderRefundController extends Controller
{
    public function __construct(private readonly OrderRefunder $refunder) {}

    public function store(RefundOrderRequest $request, Order $order): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $refund = $this->refunder->request(
                $order,
                Money::parse($validated['amount'], $order->currency),
                new RefundRequest(
                    token: $validated['token'],
                    reason: $validated['reason'] ?? null,
                    note: filled($validated['note'] ?? null) ? $validated['note'] : null,
                    restock: $request->boolean('restock'),
                    notifyCustomer: $request->boolean('notify_customer', true),
                    manual: $request->boolean('manual'),
                ),
                $request->user(),
            );
        } catch (RefundException $exception) {
            return back()->withErrors([$exception->field => $exception->getMessage()]);
        }

        return match ($refund->status) {
            RefundStatus::Succeeded => back()->with('success', $refund->isProviderRefund()
                ? "Refunded {$refund->amount} {$refund->currency}."
                : "Recorded a refund of {$refund->amount} {$refund->currency}."),
            RefundStatus::Failed, RefundStatus::Canceled => back()->with('error', 'The refund was refused'.($refund->failure_reason ? ": {$refund->failure_reason}" : '.')),
            RefundStatus::Pending => back()->with('warning', 'The refund is pending. It will update when the provider confirms it; use "Check status" if it does not.'),
        };
    }

    /**
     * Ask the provider about a refund that is still pending.
     */
    public function check(Request $request, Order $order, Refund $refund): RedirectResponse
    {
        abort_unless($refund->order_id === $order->id, 404);

        try {
            $refund = $this->refunder->check($refund);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Could not reach the payment provider: '.$exception->getMessage());
        }

        return match ($refund->status) {
            RefundStatus::Succeeded => back()->with('success', 'The refund went through.'),
            RefundStatus::Failed, RefundStatus::Canceled => back()->with('error', 'The refund did not go through'.($refund->failure_reason ? ": {$refund->failure_reason}" : '.')),
            RefundStatus::Pending => back()->with('info', 'The provider has not finished this refund yet.'),
        };
    }
}
