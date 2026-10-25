<?php

namespace App\Http\Requests\Admin;

use App\Support\Forum\ThreadPrefixes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreForumPrefixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forums.acp.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->trimmed());
    }

    /**
     * @return array<string, mixed>
     */
    private function trimmed(): array
    {
        $name = $this->input('name');
        $slug = $this->input('slug');

        return [
            'name' => is_string($name) ? trim($name) : $name,
            'slug' => is_string($slug) && trim($slug) !== '' ? trim($slug) : null,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40', 'unique:forum_prefixes,name'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:forum_prefixes,slug'],
            'color' => ['required', 'string', Rule::in(ThreadPrefixes::COLORS)],
            'boards' => ['array'],
            'boards.*' => ['integer', 'exists:forum_boards,id'],
        ];
    }
}
