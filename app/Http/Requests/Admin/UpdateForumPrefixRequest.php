<?php

namespace App\Http\Requests\Admin;

use App\Support\Forum\ThreadPrefixes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateForumPrefixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forums.acp.edit') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $slug = $this->input('slug');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'slug' => is_string($slug) && trim($slug) !== '' ? trim($slug) : null,
        ]);
    }

    public function rules(): array
    {
        $prefixId = $this->route('prefix')?->id;

        return [
            'name' => ['required', 'string', 'max:40', Rule::unique('forum_prefixes', 'name')->ignore($prefixId)],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('forum_prefixes', 'slug')->ignore($prefixId)],
            'color' => ['required', 'string', Rule::in(ThreadPrefixes::COLORS)],
            'position' => ['required', 'integer', 'min:0', 'max:100000'],
            'boards' => ['array'],
            'boards.*' => ['integer', 'exists:forum_boards,id'],
        ];
    }
}
