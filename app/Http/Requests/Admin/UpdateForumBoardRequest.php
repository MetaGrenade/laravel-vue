<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateForumBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forums.acp.edit') ?? false;
    }

    public function rules(): array
    {
        $boardId = $this->route('board')?->id;

        return [
            'forum_category_id' => ['required', 'exists:forum_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('forum_boards', 'slug')->ignore($boardId),
            ],
            'description' => ['nullable', 'string'],
        ];
    }
}
