<?php

namespace App\Http\Requests\Settings;

use App\Support\Api\ApiKeys;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateApiKeyLimitsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'hourly_quota' => ApiKeys::LIMIT_RULES,
            'daily_quota' => ApiKeys::LIMIT_RULES,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ApiKeys::limitMessages();
    }

    public function hourlyLimit(): ?int
    {
        $value = $this->validated('hourly_quota');

        return $value === null ? null : (int) $value;
    }

    public function dailyLimit(): ?int
    {
        $value = $this->validated('daily_quota');

        return $value === null ? null : (int) $value;
    }
}
