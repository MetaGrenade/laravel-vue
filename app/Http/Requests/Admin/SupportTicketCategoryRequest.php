<?php

namespace App\Http\Requests\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupportTicketCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('category');

        $categoryId = $category instanceof Model
            ? $category->getKey()
            : (is_numeric($category) ? (int) $category : null);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('support_ticket_categories', 'name')->ignore($categoryId),
            ],
        ];
    }
}
