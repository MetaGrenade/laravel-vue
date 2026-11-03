<?php

namespace App\Http\Requests\Settings;

use App\Support\Api\ApiKeys;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RotateApiKeyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'grace_hours' => ['required', 'integer', Rule::in(app(ApiKeys::class)->rotationChoices()['hours'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grace_hours.required' => __('forms.api_keys.grace_required'),
            'grace_hours.in' => __('forms.api_keys.grace_invalid'),
        ];
    }

    public function graceHours(): int
    {
        return (int) $this->validated('grace_hours');
    }
}
