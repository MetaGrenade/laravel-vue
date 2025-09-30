<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateForumCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forums.acp.edit') ?? false;
    }

    public function rules(): array
    {
        $categoryId = $this->route('category')?->id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('forum_categories', 'slug')->ignore($categoryId),
            ],
            'description' => ['nullable', 'string'],
        ];
    }
}
