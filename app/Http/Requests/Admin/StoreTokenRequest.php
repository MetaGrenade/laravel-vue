<?php

namespace App\Http\Requests\Admin;

use App\Support\Api\Scopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'name' => ['required', 'string', 'max:255'],
            // What the token can do is chosen from the site's scopes, or full access: free text meant nothing, and now a token is held to it.
            'abilities' => ['required', 'array'],
            'abilities.*' => ['string', Rule::in([Scopes::FULL, ...app(Scopes::class)->keys()])],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'user_id' => ['required', 'exists:users,id'],
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
