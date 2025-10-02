<?php

namespace App\Http\Requests\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FaqCategoryRequest extends FormRequest
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

        if ($this->has('description') && $this->input('description') === '') {
            $this->merge(['description' => null]);
        }

        if ($this->has('order')) {
            $order = $this->input('order');

            if (is_numeric($order)) {
                $this->merge(['order' => (int) $order]);
            }
        }
    }

    public function rules(): array
    {
        $category = $this->route('category');

        $categoryId = $category instanceof Model
            ? $category->getKey()
            : (is_numeric($category) ? (int) $category : null);

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('faq_categories', 'slug')->ignore($categoryId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'order' => ['required', 'integer'],
        ];
    }
}
