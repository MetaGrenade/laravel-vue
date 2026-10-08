<?php

namespace App\Http\Requests\Admin\Catalogue;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /**
     * The commerce permissions are enforced by the routes (view, create, edit, delete).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The address of a product is made of lower-case words joined by hyphens, whatever was typed.
     * Left empty it is made from the name (see {@see Product}'s controller).
     */
    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');

        $this->merge([
            'slug' => is_string($slug) && trim($slug) !== '' ? (Str::slug($slug) ?: null) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product instanceof Product ? $product->id : null)],
            'description' => ['nullable', 'string', 'max:10000'],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('product_categories', 'id')],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('product_tags', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'requires_shipping' => ['sometimes', 'boolean'],
            'is_taxable' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.unique' => 'Another product already uses this address.',
        ];
    }
}
