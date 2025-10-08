<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reputation.acp.view') ?? false
            || $this->user()?->hasAnyRole(['admin', 'editor', 'moderator']);
    }

    public function rules(): array
    {
        $badgeId = $this->route('badge')?->id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('badges', 'slug')->ignore($badgeId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'points_required' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('points_required')) {
            $this->merge([
                'points_required' => (int) $this->input('points_required'),
            ]);
        }

        if (!$this->has('is_active')) {
            $this->merge([
                'is_active' => true,
            ]);
        }
    }
}
