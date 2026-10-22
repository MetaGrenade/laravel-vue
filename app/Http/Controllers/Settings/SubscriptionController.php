<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\InteractsWithStripe;
use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\SubscriptionPlan;
use App\Support\Billing\CustomerPortal;
use App\Support\Billing\PaymentRetry;
use App\Support\Billing\PlanPresenter;
use App\Support\Billing\PortalUnavailable;
use App\Support\Billing\PromotionCodes;
use App\Support\Billing\SubscriptionManager;
use App\Support\Usage\Usage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SubscriptionController extends Controller
{
    use InteractsWithStripe;

    public function __construct(protected SubscriptionManager $subscriptions, protected CustomerPortal $portal, protected Usage $usage) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $subscription = $user?->subscription(config('billing.subscription_name', 'default'));

        $plans = SubscriptionPlan::query()
            ->active()
            ->with('entitlementLinks.entitlement')
            ->orderBy('price')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => PlanPresenter::forPage($plan))
            ->values();

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
            // Whether the trials the plans offer are for this customer: they get one, and only if they have never had a subscription.
            'trial_eligible' => $this->subscriptions->eligibleForTrial($user),
            // Whether to offer the button to Stripe's billing portal: it is on, Stripe is set up, and this customer has an account there.
            'portal' => $this->portal->availableTo($user),
            // What this account has used of each meter this month, and what it may (what its plans give, so it changes with the plan).
            'usage' => $this->usage->summary($user),
            'subscription' => $subscription ? [
                'name' => $subscription->name,
                'stripe_status' => $subscription->stripe_status,
                'stripe_price' => $subscription->stripe_price,
                'on_grace_period' => $subscription->onGracePeriod(),
                'cancelled' => $subscription->canceled(),
                'ends_at' => optional($subscription->ends_at)?->toIso8601String(),
                'on_trial' => $subscription->onTrial(),
                'trial_ends_at' => optional($subscription->trial_ends_at)?->toIso8601String(),
                // Behind on payment: the page says so and offers to pay now.
                'needs_payment' => $this->subscriptions->behindOnPayment($subscription),
                // Whether what the plan gives is paused right now: its features follow the subscription being live, so this is that
                // (a past-due subscription is not live by Cashier's default, an unpaid one never is).
                'access_paused' => ! $subscription->valid(),
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

    /**
     * What a promotion code the customer has typed takes off the plan they have chosen, or why it cannot be used. Nothing is
     * started or charged; starting the subscription looks the code up again.
     */
    public function promotionCode(Request $request, PromotionCodes $codes): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'promotion_code' => ['required', 'string', 'max:64'],
        ])->validate();

        $plan = SubscriptionPlan::query()->active()->findOrFail($data['plan_id']);

        return response()->json($codes->find($request->user(), $plan, $data['promotion_code'])->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'payment_method' => ['required', 'string'],
            'promotion_code' => ['nullable', 'string', 'max:64'],
        ])->validate();

        $plan = SubscriptionPlan::query()->active()->findOrFail($data['plan_id']);

        try {
            $subscription = $this->subscriptions->create($request->user(), $plan, $data['payment_method'], [
                'promotion_code' => $data['promotion_code'] ?? null,
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

    /**
     * Pay what a subscription is behind on, now, with the card on file (the customer has just put it right, or is about to
     * find out it still does not work).
     */
    public function retryPayment(Request $request): RedirectResponse|SymfonyResponse
    {
        $result = $this->subscriptions->retryPayment($request->user());

        return match ($result->status) {
            PaymentRetry::PAID => to_route('settings.billing.index')->with('success', 'Payment received. Your plan is active again.'),
            PaymentRetry::ACTION_REQUIRED => Inertia::location((string) $result->url),
            PaymentRetry::DECLINED => to_route('settings.billing.index')->with('error', $result->message),
            PaymentRetry::PENDING => to_route('settings.billing.index')->with('info', "Your payment is being processed. Your plan will be active again as soon as it's confirmed."),
            PaymentRetry::UNAVAILABLE => to_route('settings.billing.index')->with('error', "We couldn't reach Stripe just now. Please try again in a minute."),
            default => to_route('settings.billing.index')->with('info', 'There is nothing to pay right now.'),
        };
    }

    /**
     * Send the customer to Stripe's billing portal. A POST, since it makes a session at Stripe each time, and it always comes back to
     * the billing page.
     */
    public function portal(Request $request): RedirectResponse|SymfonyResponse
    {
        abort_unless($this->portal->enabled(), 404);

        try {
            $url = $this->portal->urlFor($request->user());
        } catch (PortalUnavailable $exception) {
            return to_route('settings.billing.index')->with($exception->level, $exception->getMessage());
        }

        return Inertia::location($url);
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
