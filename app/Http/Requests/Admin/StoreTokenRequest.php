<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTokenRequest extends FormRequest
{
    public mixed $name;
    public mixed $abilities;
    public mixed $expires_at;
    public mixed $user_id;

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
            'expires_at' => ['nullable', 'date', 'after:now'],
            'user_id'    => ['required', 'exists:users,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // ensure user_id is integer
        $this->merge([
            'user_id' => (int) $this->input('user_id'),
        ]);
    }
}
