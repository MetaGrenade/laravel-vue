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
            'grace_hours.required' => 'Choose how long the old key should go on working.',
            'grace_hours.in' => 'Choose how long the old key should go on working from the list.',
        ];
    }

    public function graceHours(): int
    {
        return (int) $this->validated('grace_hours');
    }
}
