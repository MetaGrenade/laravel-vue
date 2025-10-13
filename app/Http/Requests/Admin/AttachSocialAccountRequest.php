<?php

namespace App\Http\Requests\Admin;

use App\Support\OAuth\ProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachSocialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.acp.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(array_keys(ProviderRegistry::all()))],
            'provider_id' => ['required', 'string', 'max:191'],
            'name' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'avatar' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
