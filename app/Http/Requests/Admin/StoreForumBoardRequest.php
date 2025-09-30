<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreForumBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forums.acp.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'forum_category_id' => ['required', 'exists:forum_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:forum_boards,slug'],
            'description' => ['nullable', 'string'],
        ];
    }
}
