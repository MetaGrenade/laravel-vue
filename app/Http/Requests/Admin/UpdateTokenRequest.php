<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('tokens.acp.edit');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'clear_revocation' => ['sometimes', 'boolean'],
            'hourly_quota' => ['nullable', 'integer', 'min:1'],
            'daily_quota' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'expires_at' => $this->input('expires_at') ?: null,
            'clear_revocation' => $this->boolean('clear_revocation'),
            'hourly_quota' => $this->normalizeQuota($this->input('hourly_quota')),
            'daily_quota' => $this->normalizeQuota($this->input('daily_quota')),
        ]);
    }

    private function normalizeQuota(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }
}
