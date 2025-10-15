<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionPlanController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('billing.acp.view'), 403);

        $formatter = DateFormatter::for($request->user());

        $plans = SubscriptionPlan::query()
            ->withCount('invoices')
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
                ];
            })
            ->values()
            ->all();

        return Inertia::render('acp/BillingPlans', [
            'plans' => $plans,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('billing.acp.view'), 403);

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
        abort_unless($request->user()?->can('billing.acp.view'), 403);

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

    public function destroy(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        abort_unless($request->user()?->can('billing.acp.view'), 403);

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
