<?php

namespace App\Http\Requests\Admin;

use App\Support\Api\Scopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'abilities' => ['required', 'array'],
            'abilities.*' => ['string', Rule::in([Scopes::FULL, ...app(Scopes::class)->keys()])],
            'expires_at' => ['nullable', 'date'],
            'clear_revocation' => ['sometimes', 'boolean'],
            'hourly_quota' => ['nullable', 'integer', 'min:1'],
            'daily_quota' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'abilities.required' => 'Choose what the token can do.',
            'abilities.*.in' => 'That is not an ability the site has. Choose from the list.',
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
