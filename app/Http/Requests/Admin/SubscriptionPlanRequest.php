<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
        $name = $this->input('name');
        $description = $this->input('description');
        $currency = $this->input('currency');

        $this->merge([
            'slug' => $this->normalizeSlug($slug, $name),
            'description' => $description !== null && trim((string) $description) !== '' ? trim((string) $description) : null,
            'currency' => $currency ? strtoupper((string) $currency) : strtoupper((string) config('billing.currency', 'USD')),
            'features' => $features,
            'is_active' => $this->boolean('is_active'),
        ]);

        if ($this->has('price')) {
            $this->merge([
                'price' => $this->normalizePrice($this->input('price')),
            ]);
        }
    }

    private function normalizePrice(mixed $price): int
    {
        if ($price === null || $price === '') {
            return 0;
        }

        if (is_int($price)) {
            return $price;
        }

        if (is_float($price)) {
            return (int) round($price * 100);
        }

        if (is_string($price)) {
            $trimmed = trim($price);

            if ($trimmed === '') {
                return 0;
            }

            $sanitized = preg_replace('/[^0-9.,-]/', '', $trimmed) ?? '';

            if ($sanitized === '') {
                return 0;
            }

            $normalized = str_replace(',', '.', $sanitized);

            if (Str::contains($normalized, '.')) {
                return (int) round((float) $normalized * 100);
            }

            if (ctype_digit($normalized) || $normalized === '0') {
                return (int) $normalized;
            }

            return (int) round((float) $normalized * 100);
        }

        if (is_numeric($price)) {
            return (int) round((float) $price * 100);
        }

        return 0;
    }

    private function normalizeSlug(mixed $slug, mixed $name): ?string
    {
        $slug = $slug !== null && trim((string) $slug) !== '' ? trim((string) $slug) : null;

        if ($slug !== null) {
            return $slug;
        }

        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            return null;
        }

        $generated = Str::slug($name);

        return $generated !== '' ? $generated : null;
    }
}
