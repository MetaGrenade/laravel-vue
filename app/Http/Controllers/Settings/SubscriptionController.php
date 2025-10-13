<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\SubscriptionPlan;
use App\Support\Billing\SubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Payment;

class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionManager $subscriptions)
    {
    }

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $subscription = $user?->subscription(config('billing.subscription_name', 'default'));

        $plans = SubscriptionPlan::query()
            ->active()
            ->orderBy('price')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price' => $plan->price,
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'features' => $plan->features ?? [],
                'stripe_price_id' => $plan->stripe_price_id,
            ])->values();

        $invoices = BillingInvoice::query()
            ->where('user_id', $user?->id)
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'stripe_id', 'status', 'total', 'currency', 'created_at', 'paid_at'])
            ->map(fn (BillingInvoice $invoice) => [
                'id' => $invoice->id,
                'stripe_id' => $invoice->stripe_id,
                'status' => $invoice->status,
                'total' => $invoice->total,
                'currency' => $invoice->currency,
                'created_at' => optional($invoice->created_at)?->toIso8601String(),
                'paid_at' => optional($invoice->paid_at)?->toIso8601String(),
            ])->values();

        return Inertia::render('settings/Billing', [
            'plans' => $plans,
            'subscription' => $subscription ? [
                'name' => $subscription->name,
                'stripe_status' => $subscription->stripe_status,
                'stripe_price' => $subscription->stripe_price,
                'on_grace_period' => $subscription->onGracePeriod(),
                'cancelled' => $subscription->canceled(),
                'ends_at' => optional($subscription->ends_at)?->toIso8601String(),
            ] : null,
            'invoices' => $invoices,
        ]);
    }

    public function setupIntent(Request $request): JsonResponse
    {
        $intent = $this->shouldBypassStripe() ? (object) [
            'id' => 'seti_'.Str::random(24),
            'client_secret' => 'seti_secret_'.Str::random(40),
        ] : $request->user()->createSetupIntent();

        return response()->json([
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'payment_method' => ['required', 'string'],
            'coupon' => ['nullable', 'string'],
        ])->validate();

        /** @var SubscriptionPlan $plan */
        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        try {
            $subscription = $this->subscriptions->create($request->user(), $plan, $data['payment_method'], [
                'coupon' => $data['coupon'] ?? null,
            ]);
        } catch (IncompletePayment $exception) {
            $paymentIntent = $this->extractPaymentIntent($exception);

            return response()->json([
                'status' => 'requires_action',
                'payment_intent_id' => $paymentIntent?->id,
                'client_secret' => $paymentIntent?->client_secret,
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'stripe_status' => $subscription->stripe_status,
                'stripe_id' => $subscription->stripe_id,
            ] : null,
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->subscriptions->cancel($request->user());

        return to_route('settings.billing.index');
    }

    public function resume(Request $request): RedirectResponse
    {
        $this->subscriptions->resume($request->user());

        return to_route('settings.billing.index');
    }

    protected function shouldBypassStripe(): bool
    {
        return blank((string) config('cashier.secret')) || app()->environment('testing');
    }

    protected function extractPaymentIntent(IncompletePayment $exception): ?object
    {
        $intent = $this->normalizePaymentIntent($exception->payment ?? null);

        if ($intent !== null) {
            return $intent;
        }

        if (property_exists($exception, 'paymentIntent')) {
            $intent = $this->normalizePaymentIntent($exception->paymentIntent);

            if ($intent !== null) {
                return $intent;
            }
        }

        if (method_exists($exception, 'paymentIntent')) {
            return $this->normalizePaymentIntent($exception->paymentIntent());
        }

        return null;
    }

    protected function normalizePaymentIntent($value): ?object
    {
        if ($value instanceof Payment) {
            $value = $value->asStripePaymentIntent();
        }

        if (is_array($value)) {
            $value = (object) $value;
        }

        if (is_object($value)) {
            if ($value instanceof Payment) {
                return $this->normalizePaymentIntent($value->asStripePaymentIntent());
            }

            if (isset($value->id)) {
                return $value;
            }
        }

        return null;
    }
}
