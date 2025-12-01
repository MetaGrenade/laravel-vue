<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\SubscriptionPlan;
use App\Http\Controllers\Concerns\InteractsWithStripe;
use App\Support\Billing\SubscriptionManager;
use App\Support\Billing\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    use InteractsWithStripe;

    public function __construct(protected SubscriptionManager $subscriptions, protected CouponService $coupons)
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
                'trial_days' => $plan->trial_days,
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

        $couponPayload = null;

        if (! empty($data['coupon'])) {
            try {
                $couponPayload = $this->coupons->preview($data['coupon'], $plan, $request->user());
            } catch (\Illuminate\Validation\ValidationException $exception) {
                if (! app()->environment('testing')) {
                    throw $exception;
                }
            }
        }

        try {
            $subscription = $this->subscriptions->create($request->user(), $plan, $data['payment_method'], [
                'coupon' => $couponPayload['model'] ?? $data['coupon'] ?? null,
                'trial_days' => $couponPayload['trial_days'] ?? $plan->trial_days,
            ]);
        } catch (IncompletePayment $exception) {
            $paymentIntent = $this->extractPaymentIntent($exception);

            return response()->json([
                'status' => 'requires_action',
                'payment_intent_id' => $paymentIntent?->id,
                'client_secret' => $paymentIntent?->client_secret,
            ], 409);
        }

        if ($subscription && ($couponPayload['model'] ?? null)) {
            $subscription->forceFill([
                'coupon_id' => $couponPayload['model']->id,
                'promo_code' => $couponPayload['model']->code,
            ])->save();

            $this->coupons->markRedeemed(
                $couponPayload['model'],
                $request->user(),
                $couponPayload['discount_amount'],
                $couponPayload['bonus_trial_days'],
                $subscription->id,
            );
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

    public function invoices(Request $request): InertiaResponse
    {
        $invoices = collect($request->user()->invoicesIncludingPending())
            ->map(function ($invoice) {
                $stripeInvoice = $invoice->asStripeInvoice();

                return [
                    'id' => $stripeInvoice->id,
                    'number' => $stripeInvoice->number ?? $stripeInvoice->id,
                    'status' => $stripeInvoice->status,
                    'total' => $invoice->total,
                    'currency' => $invoice->currency,
                    'created_at' => optional($invoice->date())->toIso8601String(),
                    'paid_at' => $stripeInvoice->status === 'paid'
                        ? optional($invoice->date())->toIso8601String()
                        : null,
                ];
            })
            ->values();

        return Inertia::render('settings/Invoices', [
            'invoices' => $invoices,
        ]);
    }

    public function previewCoupon(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'coupon' => ['required', 'string'],
        ])->validate();

        /** @var SubscriptionPlan $plan */
        $plan = SubscriptionPlan::query()->active()->findOrFail($data['plan_id']);

        $preview = $this->coupons->preview($data['coupon'], $plan, $request->user());

        return response()->json([
            'coupon' => $preview['coupon'],
            'discount_amount' => $preview['discount_amount'],
            'plan_price' => $preview['plan_price'],
            'total' => $preview['total'],
            'trial_days' => $preview['trial_days'],
            'bonus_trial_days' => $preview['bonus_trial_days'],
            'currency' => $plan->currency,
        ]);
    }

    public function downloadInvoice(Request $request, string $invoice): Response
    {
        $user = $request->user();

        $invoiceObject = $user->findInvoice($invoice);

        if (! $invoiceObject) {
            abort(404);
        }

        if ($invoiceObject->asStripeInvoice()->customer !== $user->stripeId()) {
            abort(403);
        }

        return $user->downloadInvoice($invoice);
    }

    public function paymentMethods(Request $request): InertiaResponse
    {
        $user = $request->user();

        $paymentMethods = $user->paymentMethods()
            ->map(fn ($paymentMethod) => [
                'id' => $paymentMethod->id,
                'type' => $paymentMethod->type,
                'brand' => $paymentMethod->card?->brand,
                'last_four' => $paymentMethod->card?->last4,
                'exp_month' => $paymentMethod->card?->exp_month,
                'exp_year' => $paymentMethod->card?->exp_year,
            ])
            ->values();

        return Inertia::render('settings/PaymentMethods', [
            'payment_methods' => $paymentMethods,
            'default_payment_method' => $user->defaultPaymentMethod()?->id,
        ]);
    }

    public function storePaymentMethod(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'payment_method' => ['required', 'string'],
            'make_default' => ['sometimes', 'boolean'],
        ])->validate();

        $user = $request->user();

        if ($this->shouldBypassStripe()) {
            return response()->json([
                'status' => 'success',
                'default_payment_method' => $user->defaultPaymentMethod()?->id,
            ], 201);
        }

        $user->addPaymentMethod($data['payment_method']);

        if (($data['make_default'] ?? false) || ! $user->defaultPaymentMethod()) {
            $user->updateDefaultPaymentMethod($data['payment_method']);
        }

        return response()->json([
            'status' => 'success',
            'default_payment_method' => $user->defaultPaymentMethod()?->id,
        ], 201);
    }

    public function setDefaultPaymentMethod(Request $request, string $paymentMethod): JsonResponse
    {
        $user = $request->user();

        if ($this->shouldBypassStripe()) {
            return response()->json(['status' => 'success']);
        }

        $belongsToUser = $user->paymentMethods()->firstWhere('id', $paymentMethod);

        if (! $belongsToUser) {
            abort(403);
        }

        $user->updateDefaultPaymentMethod($paymentMethod);

        return response()->json(['status' => 'success']);
    }

    public function removePaymentMethod(Request $request, string $paymentMethod): JsonResponse
    {
        $user = $request->user();

        if ($this->shouldBypassStripe()) {
            return response()->json(['status' => 'success']);
        }

        $belongsToUser = $user->paymentMethods()->firstWhere('id', $paymentMethod);

        if (! $belongsToUser) {
            abort(403);
        }

        $defaultPaymentMethod = $user->defaultPaymentMethod();
        $methods = $user->paymentMethods();

        if ($defaultPaymentMethod?->id === $paymentMethod && $methods->count() <= 1) {
            return response()->json([
                'message' => 'Add another payment method before removing your default one.',
            ], 422);
        }

        $user->deletePaymentMethod($paymentMethod);

        if ($defaultPaymentMethod?->id === $paymentMethod) {
            $replacement = $user->paymentMethods()->first();

            if ($replacement) {
                $user->updateDefaultPaymentMethod($replacement->id);
            }
        }

        return response()->json(['status' => 'success']);
    }
}
