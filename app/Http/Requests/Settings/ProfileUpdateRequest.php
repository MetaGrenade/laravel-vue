<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->exists('profile_bio')) {
            $bio = is_string($this->input('profile_bio')) ? trim($this->input('profile_bio')) : '';
            $payload['profile_bio'] = $bio !== '' ? $bio : null;
        }

        if ($this->exists('social_links')) {
            $socialLinks = $this->input('social_links');

            if (! is_array($socialLinks)) {
                $socialLinks = [];
            } else {
                $socialLinks = array_values(array_map(
                    fn ($link) => is_array($link) ? $link : [],
                    $socialLinks,
                ));
            }

            $payload['social_links'] = $socialLinks;
        }

        if (! empty($payload)) {
            $this->merge($payload);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nickname' => [
                'required',
                'string',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id)
            ],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'avatar' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,gif,webp',
                'max:2048',
            ],
            'forum_signature' => [
                'nullable',
                'string',
                'max:500',
            ],
            'profile_bio' => [
                'nullable',
                'string',
            ],
            'social_links' => [
                'nullable',
                'array',
            ],
            'social_links.*.label' => [
                'nullable',
                'string',
                'max:255',
            ],
            'social_links.*.url' => [
                'nullable',
                'url',
                'max:2048',
            ],
        ];
    }
}
