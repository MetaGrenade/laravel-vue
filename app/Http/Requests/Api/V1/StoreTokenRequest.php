<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Support\Api\Scopes;
use App\Support\Members\EmailLookup;
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
        // Whatever the case the address is typed in (EmailLookup): a phone capitalises the first letter, and MySQL and MariaDB would not mind.
        return EmailLookup::find((string) $this->input('email'));
    }
}
