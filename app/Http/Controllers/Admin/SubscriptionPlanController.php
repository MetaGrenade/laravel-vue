<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionPlanRequest;
use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The subscription plans. The routes decide who may do what: `billing.acp.view` to look, and `billing.acp.create`, `edit` or
 * `delete` for the matching action.
 */
class SubscriptionPlanController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('billing.acp.view'), 403);

        $formatter = DateFormatter::for($request->user());

        $plans = SubscriptionPlan::query()
            ->withCount('invoices')
            ->with('entitlementLinks.entitlement')
            ->orderBy('price')
            ->get()
            ->map(function (SubscriptionPlan $plan) use ($formatter) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'stripe_price_id' => $plan->stripe_price_id,
                    'interval' => $plan->interval,
                    'price' => $plan->price,
                    'currency' => $plan->currency,
                    'description' => $plan->description,
                    'features' => $plan->features ?? [],
                    'is_active' => $plan->is_active,
                    'created_at' => $formatter->iso($plan->created_at),
                    'updated_at' => $formatter->iso($plan->updated_at),
                    'invoices_count' => $plan->invoices_count,
                    'entitlements' => $plan->entitlementLinks
                        ->sortBy(fn ($link) => mb_strtolower($link->entitlement->label))
                        ->map(fn ($link) => [
                            'id' => $link->entitlement->id,
                            'key' => $link->entitlement->key,
                            'label' => $link->entitlement->label,
                            'type' => $link->entitlement->type->value,
                            'value' => $link->value,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('acp/BillingPlans', [
            'plans' => $plans,
            // Everything a plan can be set to give.
            'entitlements' => Entitlement::query()->orderBy('label')->get()
                ->map(fn (Entitlement $entitlement) => [
                    'id' => $entitlement->id,
                    'key' => $entitlement->key,
                    'label' => $entitlement->label,
                    'type' => $entitlement->type->value,
                ])
                ->all(),
            // What the buttons on the page offer: each action has its own permission.
            'can' => [
                'create' => $request->user()->can('billing.acp.create'),
                'edit' => $request->user()->can('billing.acp.edit'),
                'delete' => $request->user()->can('billing.acp.delete'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('acp/BillingPlanCreate', [
            'intervals' => $this->intervalOptions(),
            'default_currency' => strtoupper((string) config('billing.currency', 'USD')),
        ]);
    }

    public function store(SubscriptionPlanRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        SubscriptionPlan::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? null,
            'stripe_price_id' => $validated['stripe_price_id'],
            'interval' => $validated['interval'],
            'price' => $validated['price'],
            'currency' => strtoupper($validated['currency']),
            'description' => $validated['description'] ?? null,
            'features' => $this->normalizeFeatures($validated['features'] ?? []),
            'is_active' => $validated['is_active'],
        ]);

        return redirect()
            ->route('acp.billing.plans.index')
            ->with('success', 'Subscription plan created successfully.');
    }

    public function edit(Request $request, SubscriptionPlan $plan): Response
    {
        $formatter = DateFormatter::for($request->user());

        $plan->loadCount('invoices');

        return Inertia::render('acp/BillingPlanEdit', [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'stripe_price_id' => $plan->stripe_price_id,
                'interval' => $plan->interval,
                'price' => $plan->price,
                'currency' => $plan->currency,
                'description' => $plan->description,
                'features' => $plan->features ?? [],
                'is_active' => $plan->is_active,
                'created_at' => $formatter->iso($plan->created_at),
                'updated_at' => $formatter->iso($plan->updated_at),
                'invoices_count' => $plan->invoices_count,
            ],
            'intervals' => $this->intervalOptions(),
            'default_currency' => strtoupper((string) config('billing.currency', 'USD')),
        ]);
    }

    public function update(SubscriptionPlanRequest $request, SubscriptionPlan $plan): RedirectResponse
    {
        $validated = $request->validated();

        // The Stripe price is what ties a subscription to its plan, and so to what the plan gives: pointing the plan
        // somewhere else would leave the people on it with nothing.
        if ($validated['stripe_price_id'] !== $plan->stripe_price_id && $plan->hasLiveSubscribers()) {
            throw ValidationException::withMessages([
                'stripe_price_id' => 'People are subscribed at this price now, and would lose what the plan gives. Make a new plan for the new price instead.',
            ]);
        }

        $plan->forceFill([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? null,
            'stripe_price_id' => $validated['stripe_price_id'],
            'interval' => $validated['interval'],
            'price' => $validated['price'],
            'currency' => strtoupper($validated['currency']),
            'description' => $validated['description'] ?? null,
            'features' => $this->normalizeFeatures($validated['features'] ?? []),
            'is_active' => $validated['is_active'],
        ])->save();

        return redirect()
            ->route('acp.billing.plans.index')
            ->with('success', 'Subscription plan updated successfully.');
    }

    public function destroy(SubscriptionPlan $plan): RedirectResponse
    {
        if ($plan->hasLiveSubscribers()) {
            return back()->with('error', "People are subscribed to {$plan->name} now, and would lose what it gives. Switch the plan off instead: it stops being offered and keeps serving the people already on it.");
        }

        $plan->delete();

        return redirect()
            ->route('acp.billing.plans.index')
            ->with('success', 'Subscription plan deleted successfully.');
    }

    /**
     * @return array<int, string>
     */
    protected function intervalOptions(): array
    {
        return ['day', 'week', 'month', 'year'];
    }

    /**
     * @param  array<int, string>|string|null  $features
     * @return array<int, string>
     */
    protected function normalizeFeatures($features): array
    {
        if (is_string($features)) {
            $features = preg_split('/\r\n|\r|\n/', $features) ?: [];
        }

        if (! is_array($features)) {
            return [];
        }

        return collect($features)
            ->map(static fn ($feature) => is_string($feature) ? trim($feature) : '')
            ->filter(static fn ($feature) => $feature !== '')
            ->values()
            ->all();
    }
}
