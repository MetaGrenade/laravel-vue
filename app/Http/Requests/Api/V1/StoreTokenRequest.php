<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Support\Api\Scopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:191'],
            'abilities' => ['nullable', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in([Scopes::FULL, ...app(Scopes::class)->keys()])],
        ];
    }

    public function userFromCredentials(): ?User
    {
        return User::where('email', $this->input('email'))->first();
    }
}
