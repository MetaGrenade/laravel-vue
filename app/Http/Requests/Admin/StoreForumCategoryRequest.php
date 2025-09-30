<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreForumCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forums.acp.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:forum_categories,slug'],
            'description' => ['nullable', 'string'],
        ];
    }
}
