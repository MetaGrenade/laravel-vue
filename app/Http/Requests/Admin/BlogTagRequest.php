<?php

namespace App\Http\Requests\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlogTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug') && $this->input('slug') === '') {
            $this->merge(['slug' => null]);
        }
    }

    public function rules(): array
    {
        $tag = $this->route('tag');

        $tagId = $tag instanceof Model
            ? $tag->getKey()
            : (is_numeric($tag) ? (int) $tag : null);

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('blog_tags', 'slug')->ignore($tagId),
            ],
        ];
    }
}
