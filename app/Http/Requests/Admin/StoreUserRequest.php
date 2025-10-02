<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('users.acp.create');
    }

    protected function prepareForValidation(): void
    {
        $socialLinks = $this->input('social_links');

        if (! is_array($socialLinks)) {
            $socialLinks = [];
        } else {
            $socialLinks = array_values(array_map(
                fn ($link) => is_array($link) ? $link : [],
                $socialLinks,
            ));
        }

        $this->merge([
            'avatar_url' => $this->filled('avatar_url') ? $this->input('avatar_url') : null,
            'profile_bio' => $this->filled('profile_bio') ? $this->input('profile_bio') : null,
            'social_links' => $socialLinks,
        ]);
    }

    public function rules()
    {
        return [
            'nickname'  => 'required|string|max:255|unique:users,nickname',
            'email' => 'required|email|unique:users,email',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
            'avatar_url' => 'nullable|url|max:2048',
            'profile_bio' => 'nullable|string',
            'social_links' => 'nullable|array',
            'social_links.*.label' => 'nullable|string|max:255',
            'social_links.*.url' => 'nullable|url|max:2048',
        ];
    }
}
