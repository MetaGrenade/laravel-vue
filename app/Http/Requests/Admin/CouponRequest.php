<?php

namespace App\Http\Requests\Admin;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Support\Commerce\Discounts\CouponCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponRequest extends FormRequest
{
    /** The most a fixed amount can be: `coupons.value` is decimal(12,4), which holds eight digits before the point. */
    public const MAX_AMOUNT = '99999999.99';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        // Codes are kept in one form, so uniqueness is checked on that form.
        if (is_string($this->input('code'))) {
            $merge['code'] = CouponCode::normalise($this->input('code'));
        }

        foreach (['description', 'value', 'minimum_subtotal', 'starts_at', 'ends_at', 'max_redemptions', 'max_redemptions_per_customer'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $coupon = $this->route('coupon');
        $type = CouponType::tryFrom((string) $this->input('type'));

        return [
            'code' => [
                'required',
                'string',
                'max:40',
                'regex:'.CouponCode::PATTERN,
                Rule::unique('coupons', 'code')->ignore($coupon instanceof Coupon ? $coupon->id : null),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CouponType::class)],
            // Free shipping has no amount; the others need one, a percentage up to 100 or an amount of money.
            'value' => $type === CouponType::FreeShipping
                ? ['nullable']
                : [
                    'required',
                    'numeric',
                    'gt:0',
                    $type === CouponType::Percent ? 'max:100' : 'max:'.self::MAX_AMOUNT,
                    $type === CouponType::Percent ? 'decimal:0,4' : 'decimal:0,2',
                ],
            'minimum_subtotal' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => array_filter(['nullable', 'date', $this->filled('starts_at') ? 'after:starts_at' : null]),
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'max_redemptions_per_customer' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
            'product_ids' => ['nullable', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'category_ids' => ['nullable', 'array', 'max:500'],
            'category_ids.*' => ['integer', 'distinct', 'exists:product_categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'A code can only contain letters, numbers, hyphens and underscores.',
            'code.unique' => 'There is already a code like this.',
            'value.required' => 'Enter how much this code takes off.',
            'value.gt' => 'This must be more than nothing.',
            'ends_at.after' => 'The code must end after it starts.',
        ];
    }
}
