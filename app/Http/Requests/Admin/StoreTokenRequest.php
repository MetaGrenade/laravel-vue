<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        // only admins (or whoever) may create tokens
        return $this->user()->can('tokens.acp.create');
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:255'],
            'abilities'  => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'user_id'    => ['required', 'exists:users,id'],
            'hourly_quota' => ['nullable', 'integer', 'min:1'],
            'daily_quota' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // ensure user_id is integer
        $this->merge([
            'user_id' => (int) $this->input('user_id'),
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
