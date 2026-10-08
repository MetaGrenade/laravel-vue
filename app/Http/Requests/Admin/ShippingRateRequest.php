<?php

namespace App\Http\Requests\Admin;

use App\Support\Commerce\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ShippingRateRequest extends FormRequest
{
    /**
     * An amount with at most two decimals, for example 5 or 5.00. The prices are stored with two.
     */
    private const AMOUNT = '/^\d{1,8}(\.\d{1,2})?$/';

    /**
     * The commerce permissions are enforced by the routes (view, create, edit, delete).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'regex:'.self::AMOUNT],
            'min_subtotal' => ['nullable', 'regex:'.self::AMOUNT],
            'max_subtotal' => ['nullable', 'regex:'.self::AMOUNT],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter an amount such as 5 or 5.00.',
            'min_subtotal.regex' => 'Enter an amount such as 50 or 50.00.',
            'max_subtotal.regex' => 'Enter an amount such as 99.99.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['min_subtotal', 'max_subtotal'])) {
                return;
            }

            $min = $this->input('min_subtotal');
            $max = $this->input('max_subtotal');

            if (blank($min) || blank($max)) {
                return;
            }

            $currency = (string) config('commerce.currency', 'USD');

            if (Money::parse((string) $min, $currency)->minor > Money::parse((string) $max, $currency)->minor) {
                $validator->errors()->add('max_subtotal', 'The maximum order value cannot be less than the minimum.');
            }
        });
    }
}
