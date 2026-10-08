<?php

namespace App\Http\Requests\Admin;

use App\Rules\CountryCode;
use App\Support\Commerce\Countries;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class TaxRateRequest extends FormRequest
{
    /**
     * A percentage with up to four decimals, for example 20 or 8.875.
     */
    private const PERCENT = '/^\d{1,3}(\.\d{1,4})?$/';

    /**
     * The commerce permissions are enforced by the routes (view, create, edit, delete).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($this->input('country')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', new CountryCode(allowAny: true)],
            'region' => ['nullable', 'string', 'max:120'],
            'rate' => ['required', 'regex:'.self::PERCENT],
            'applies_to_shipping' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['rate.regex' => 'Enter a percentage such as 20 or 8.875.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $validator->errors()->has('rate') && (float) $this->input('rate') > 100) {
                $validator->errors()->add('rate', 'A tax rate cannot be more than 100%.');
            }

            if ($this->input('country') === Countries::ANY && filled($this->input('region'))) {
                $validator->errors()->add('region', 'A region only applies to a specific country, not to the rest-of-world fallback.');
            }
        });
    }
}
