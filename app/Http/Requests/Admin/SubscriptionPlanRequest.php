<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.acp.view')
            || $this->user()?->hasAnyRole(['admin', 'editor', 'moderator']);
    }

    public function rules(): array
    {
        $planId = $this->route('plan')?->id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('subscription_plans', 'slug')->ignore($planId),
            ],
            'stripe_price_id' => [
                'required',
                'string',
                'max:191',
                Rule::unique('subscription_plans', 'stripe_price_id')->ignore($planId),
            ],
            'interval' => ['required', Rule::in(['day', 'week', 'month', 'year'])],
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['required', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:255'],
            'features' => ['nullable', 'array', 'max:50'],
            'features.*' => ['string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $features = $this->input('features');

        if (is_string($features)) {
            $features = preg_split('/\r\n|\r|\n/', $features) ?: [];
        }

        if (! is_array($features)) {
            $features = [];
        }

        $features = collect($features)
            ->map(static fn ($feature) => is_string($feature) ? trim($feature) : '')
            ->filter(static fn ($feature) => $feature !== '')
            ->values()
            ->all();

        $slug = $this->input('slug');
        $description = $this->input('description');
        $currency = $this->input('currency');

        $this->merge([
            'slug' => $slug !== null && trim((string) $slug) !== '' ? trim((string) $slug) : null,
            'description' => $description !== null && trim((string) $description) !== '' ? trim((string) $description) : null,
            'currency' => $currency ? strtoupper((string) $currency) : strtoupper((string) config('billing.currency', 'USD')),
            'features' => $features,
            'is_active' => $this->boolean('is_active'),
        ]);

        if ($this->has('price')) {
            $this->merge([
                'price' => (int) $this->input('price'),
            ]);
        }
    }
}
