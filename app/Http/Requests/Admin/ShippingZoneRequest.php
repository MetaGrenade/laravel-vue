<?php

namespace App\Http\Requests\Admin;

use App\Rules\CountryCode;
use App\Support\Commerce\Countries;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ShippingZoneRequest extends FormRequest
{
    /**
     * The commerce permissions are enforced by the routes (view, create, edit, delete).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $countries = $this->input('countries');

        if (is_array($countries)) {
            $normalised = array_map(fn ($code) => is_string($code) ? strtoupper(trim($code)) : $code, $countries);

            $this->merge(['countries' => array_values(array_unique($normalised, SORT_REGULAR))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'countries' => ['required', 'array', 'min:1', 'max:300'],
            'countries.*' => ['string', new CountryCode(allowAny: true)],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['countries.*' => 'country', 'countries' => 'countries'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $countries = (array) $this->input('countries');

            if (in_array(Countries::ANY, $countries, true) && count($countries) > 1) {
                $validator->errors()->add(
                    'countries',
                    'Rest of world already covers every country not listed in another zone, so it cannot be combined with specific countries.',
                );
            }
        });
    }
}
