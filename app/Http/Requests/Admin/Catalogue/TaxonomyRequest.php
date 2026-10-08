<?php

namespace App\Http\Requests\Admin\Catalogue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A brand, category or tag. They share their shape; the route says which table.
 */
class TaxonomyRequest extends FormRequest
{
    /**
     * The route's {type} and the table it is kept in.
     *
     * @var array<string, string>
     */
    public const TABLES = [
        'brands' => 'brands',
        'categories' => 'product_categories',
        'tags' => 'product_tags',
    ];

    public function authorize(): bool
    {
        return true;
    }

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
        $table = self::TABLES[(string) $this->route('type')] ?? throw new \LogicException('Unknown taxonomy.');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique($table, 'slug')->ignore($this->route('id'))],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.unique' => 'Another entry already uses this address.',
        ];
    }
}
