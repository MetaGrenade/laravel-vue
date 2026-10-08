<?php

namespace App\Http\Requests\Admin\Catalogue;

use App\Models\Product;
use App\Support\Commerce\Catalogue\StockAdjuster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            // Empty tracks the product itself; otherwise one of its variants.
            'product_variant_id' => [
                'nullable', 'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $product instanceof Product ? $product->id : null),
            ],
            'quantity' => ['required', 'integer', 'min:0', 'max:'.StockAdjuster::LIMIT],
            'allow_backorder' => ['sometimes', 'boolean'],
        ];
    }
}
