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
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'expires_at' => $this->input('expires_at') ?: null,
            'clear_revocation' => $this->boolean('clear_revocation'),
        ]);
    }
}
