<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FulfilOrderRequest;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Capability;
use App\Payments\PaymentManager;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Staff view of orders: find them, see what happened, mark them fulfilled, cancel
 * unpaid ones and keep notes. Money going back is {@see CommerceOrderRefundController}.
 */
class CommerceOrderController extends Controller
{
    use InteractsWithInertiaPagination;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly OrderLifecycle $lifecycle,
        private readonly OrderRefunder $refunder,
        private readonly PaymentManager $payments,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = OrderStatus::tryFrom((string) $request->query('status', ''));
        $paymentStatus = OrderPaymentStatus::tryFrom((string) $request->query('payment_status', ''));

        $paginator = Order::query()
            ->withCount('items')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->whereLike('number', "%{$search}%")
                        ->orWhereLike('customer_email', "%{$search}%")
                        ->orWhereLike('customer_name', "%{$search}%");
                });
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status?->value))
            ->when($paymentStatus !== null, fn ($query) => $query->where('payment_status', $paymentStatus?->value))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $orders = $paginator->getCollection()->map(fn (Order $order) => [
            'public_id' => $order->public_id,
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'payment_status' => $order->payment_status->value,
            'payment_status_label' => $order->payment_status->label(),
            'currency' => $order->currency,
            'grand_total' => $order->grand_total,
            'refunded_total' => $order->refunded_total,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'items_count' => $order->items_count,
            'placed_at' => ($order->placed_at ?? $order->created_at)?->toIso8601String(),
        ])->values()->all();

        return Inertia::render('acp/CommerceOrders', [
            'orders' => [
                'data' => $orders,
                ...$this->inertiaPagination($paginator),
            ],
            'filters' => [
                'search' => $search,
                'status' => $status->value ?? '',
                'payment_status' => $paymentStatus->value ?? '',
            ],
            'statuses' => array_map(fn (OrderStatus $case) => ['value' => $case->value, 'label' => $case->label()], OrderStatus::cases()),
            'paymentStatuses' => array_map(fn (OrderPaymentStatus $case) => ['value' => $case->value, 'label' => $case->label()], OrderPaymentStatus::cases()),
            // How many orders are in each state, so the orders waiting to ship are easy to spot.
            'counts' => Order::query()
                ->select('status', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status'),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $user = $request->user();
        $order->load(['items', 'payments', 'refunds.user:id,nickname', 'events.user:id,nickname', 'user:id,nickname,email']);

        $refundable = $this->refunder->refundable($order);
        $primary = $this->refunder->primaryPayment($order);

        return Inertia::render('acp/CommerceOrderShow', [
            'order' => [
                'public_id' => $order->public_id,
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'payment_status' => $order->payment_status->value,
                'payment_status_label' => $order->payment_status->label(),
                'currency' => $order->currency,
                'subtotal' => $order->subtotal,
                'tax_total' => $order->tax_total,
                'shipping_total' => $order->shipping_total,
                'discount_total' => $order->discount_total,
                'coupon_code' => $order->coupon_code,
                'grand_total' => $order->grand_total,
                'refunded_total' => $order->refunded_total,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'customer' => $order->user ? [
                    'id' => $order->user->id,
                    'nickname' => $order->user->nickname,
                    'email' => $order->user->email,
                ] : null,
                'shipping_method' => $order->shipping_method,
                'shipping_address' => $order->shipping_address,
                'billing_address' => $order->billing_address,
                'tax_lines' => $order->metadata['tax_lines'] ?? [],
                'shipment' => $order->metadata['shipment'] ?? null,
                // Paid just as the order was being cancelled: a person should check it.
                'late_payment' => (bool) ($order->metadata['late_payment'] ?? false),
                'placed_at' => ($order->placed_at ?? $order->created_at)?->toIso8601String(),
                'paid_at' => $order->paid_at?->toIso8601String(),
                'fulfilled_at' => $order->fulfilled_at?->toIso8601String(),
                'cancelled_at' => $order->cancelled_at?->toIso8601String(),
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                ])->values(),
            ],
            'payments' => $order->payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'provider' => $payment->provider,
                'status' => $payment->status->value,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'reference' => $payment->provider_payment_id ?? $payment->provider_reference,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),
                'url' => $this->dashboardUrl($payment),
            ])->values(),
            'refunds' => $order->refunds->sortByDesc('id')->map(fn (Refund $refund) => [
                'id' => $refund->id,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'status' => $refund->status->value,
                'status_label' => $refund->status->label(),
                'reason' => $refund->reason,
                'note' => $refund->note,
                'failure_reason' => $refund->failure_reason,
                'provider' => $refund->provider,
                'reference' => $refund->provider_reference,
                'restock' => $refund->restock,
                'issued_by' => $refund->user?->nickname,
                'created_at' => $refund->created_at?->toIso8601String(),
                'processed_at' => $refund->processed_at?->toIso8601String(),
                'can_check' => (bool) $user?->can('commerce.acp.refund')
                    && $refund->status === RefundStatus::Pending
                    && $refund->isProviderRefund(),
            ])->values(),
            'events' => $order->events->sortByDesc('id')->map(fn (OrderEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'message' => $event->message,
                'by' => $event->user?->nickname,
                'created_at' => $event->created_at->toIso8601String(),
            ])->values(),
            'refunding' => [
                'refundable' => $refundable->toDecimal(),
                'can_refund' => $order->isPaid() && ! $refundable->isZero(),
                // Whether the provider can send the money back itself. If not, staff can only
                // record a refund they made some other way.
                'via_provider' => $this->canRefundThroughProvider($primary),
                'provider_label' => $primary ? $this->providerLabel($primary->provider) : null,
                'reasons' => [
                    ['value' => 'requested_by_customer', 'label' => 'Customer asked for it'],
                    ['value' => 'duplicate', 'label' => 'Duplicate payment'],
                    ['value' => 'fraudulent', 'label' => 'Fraudulent'],
                    ['value' => 'other', 'label' => 'Other'],
                ],
            ],
            'actions' => [
                'can_fulfil' => $order->status === OrderStatus::Processing && $order->isPaid(),
                'can_cancel' => $order->status === OrderStatus::Pending && ! $order->isPaid(),
                'can_check_payment' => $order->status === OrderStatus::Pending
                    && $order->payments->contains(fn (Payment $payment) => $payment->status === PaymentStatus::Pending),
            ],
            'can' => [
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'refund' => (bool) $user?->can('commerce.acp.refund'),
            ],
        ]);
    }

    public function fulfil(FulfilOrderRequest $request, Order $order): RedirectResponse
    {
        $validated = $request->validated();

        $done = $this->lifecycle->fulfil(
            $order,
            [
                'carrier' => $validated['carrier'] ?? null,
                'tracking_number' => $validated['tracking_number'] ?? null,
                'tracking_url' => $validated['tracking_url'] ?? null,
            ],
            $request->boolean('notify_customer', true),
            $request->user(),
        );

        return $done
            ? back()->with('success', 'Order marked as fulfilled.')
            : back()->with('error', 'This order is not waiting to be fulfilled.');
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        // Only an unpaid order is cancelled; a paid one is refunded instead.
        $done = $this->lifecycle->cancel($order, by: $request->user());

        return $done
            ? back()->with('success', 'Order cancelled and its stock released.')
            : back()->with('error', 'Only an unpaid order can be cancelled. Refund a paid order instead.');
    }

    public function storeNote(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        OrderEvent::record($order, OrderEvent::NOTE, $validated['note'], by: $request->user());

        return back()->with('success', 'Note added.');
    }

    /**
     * Ask the payment provider what became of an unpaid order's payment, for when its
     * message was late or lost.
     */
    public function checkPayment(Order $order): RedirectResponse
    {
        $payment = $order->payments()->where('status', PaymentStatus::Pending->value)->latest('id')->first();

        if ($payment === null) {
            return back()->with('error', 'There is no pending payment to check.');
        }

        try {
            $this->payments->provider($payment->provider)->reconcile($payment);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Could not reach the payment provider: '.$exception->getMessage());
        }

        return back()->with('success', 'Checked with the payment provider.');
    }

    private function canRefundThroughProvider(?Payment $payment): bool
    {
        if ($payment === null || blank($payment->provider_payment_id)) {
            return false;
        }

        try {
            return in_array(Capability::REFUNDS, $this->payments->provider($payment->provider)->capabilities(), true);
        } catch (Throwable) {
            return false;
        }
    }

    private function providerLabel(string $key): string
    {
        try {
            return $this->payments->provider($key)->label();
        } catch (Throwable) {
            return ucfirst($key);
        }
    }

    private function dashboardUrl(Payment $payment): ?string
    {
        try {
            return $this->payments->provider($payment->provider)->paymentUrl($payment);
        } catch (Throwable) {
            return null;
        }
    }
}
