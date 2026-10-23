<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Support\Api\ApiKeys;
use App\Support\Api\Scopes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $offered = $user instanceof User ? array_keys(app(Scopes::class)->offeredTo($user)) : [];

        $expiry = app(ApiKeys::class)->expiryChoices();
        $lifetimes = array_map('strval', $expiry['days']);

        if ($expiry['never']) {
            $lifetimes[] = 'never';
        }

        return [
            'name' => ['required', 'string', 'max:100'],
            // Never the full-access scope: a person narrows a key to what it is for, and staff are the ones who can make one without.
            'scopes' => ['required', 'array'],
            'scopes.*' => ['string', Rule::in($offered)],
            'expires_in' => ['required', 'string', Rule::in($lifetimes)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the key a name, so you can tell it from your others.',
            'scopes.required' => 'Choose at least one thing the key can do.',
            'scopes.*.in' => 'That is not something a key can be limited to.',
            'expires_in.required' => 'Choose how long the key should last.',
            'expires_in.in' => 'Choose how long the key should last from the list.',
        ];
    }

    /**
     * How many days the key lasts, or null for no end.
     */
    public function lifetimeInDays(): ?int
    {
        $value = $this->validated('expires_in');

        return $value === 'never' ? null : (int) $value;
    }

    /**
     * @return list<string>
     */
    public function scopesRequested(): array
    {
        return array_values((array) $this->validated('scopes'));
    }
}
