<?php

namespace App\Http\Requests\Admin\Catalogue;

use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Values may be typed as one list ("S, M, L", or one per line) when an option is created.
     */
    protected function prepareForValidation(): void
    {
        $values = $this->input('values');

        if (is_string($values)) {
            $values = preg_split('/[\r\n,]+/', $values) ?: [];
        }

        if (is_array($values)) {
            $values = array_values(array_unique(array_filter(
                array_map(fn ($value) => is_string($value) ? trim($value) : $value, $values),
                fn ($value) => $value !== '' && $value !== null,
            )));

            $this->merge(['values' => $values]);
        }

        $name = $this->input('name');

        if (is_string($name) && trim($this->input('display_name', '')) === '') {
            $this->merge(['display_name' => trim($name)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $option = $this->route('option');
        $product = $this->route('product');

        $productId = $option instanceof ProductOption ? $option->product_id : ($product instanceof Product ? $product->id : null);

        return [
            // The name is what variants record, so it is unique within the product.
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('product_options', 'name')
                    ->where('product_id', $productId)
                    ->ignore($option instanceof ProductOption ? $option->id : null),
            ],
            'display_name' => ['required', 'string', 'max:120'],
            'values' => ['sometimes', 'array', 'max:50'],
            'values.*' => ['string', 'max:120', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'This product already has an option with that name.',
        ];
    }
}
