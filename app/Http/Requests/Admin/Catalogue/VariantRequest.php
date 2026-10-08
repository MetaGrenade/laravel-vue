<?php

namespace App\Http\Requests\Admin\Catalogue;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use App\Support\Commerce\Catalogue\VariantGenerator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class VariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The variant being edited, if any (the route names a product when creating one).
     */
    private function variant(): ?ProductVariant
    {
        $variant = $this->route('variant');

        return $variant instanceof ProductVariant ? $variant : null;
    }

    private function product(): Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : $this->variant()->product ?? throw new \LogicException('A variant request needs a product.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')->ignore($this->variant()?->id)],
            'option_values' => ['nullable', 'array'],
            'option_values.*' => ['string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.unique' => 'Another variant already uses this SKU.',
        ];
    }

    /**
     * A variant is one combination of the product's options, and no combination appears twice.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // The default is changed by choosing another one, never by clearing it, so a product
            // that has variants always has a default.
            if ($this->variant()?->is_default && $this->has('is_default') && ! $this->boolean('is_default')) {
                $validator->errors()->add('is_default', 'A product needs a default variant. Make another variant the default instead.');
            }

            if ($validator->errors()->has('option_values') || $validator->errors()->has('option_values.*')) {
                return;
            }

            $product = $this->product();
            $chosen = array_map('strval', (array) $this->input('option_values', []));

            /** @var Collection<int, ProductOption> $options */
            $options = $product->options()->with('values')->get()->filter(fn (ProductOption $option) => $option->values->isNotEmpty());

            if ($options->isEmpty()) {
                if ($chosen !== []) {
                    $validator->errors()->add('option_values', 'This product has no options yet. Add options before choosing values for a variant.');
                }

                return;
            }

            foreach ($chosen as $name => $value) {
                $option = $options->firstWhere('name', $name);

                if ($option === null || ! $option->values->contains('value', $value)) {
                    $validator->errors()->add('option_values', "“{$name}: {$value}” is not one of this product's options.");

                    return;
                }
            }

            foreach ($options as $option) {
                if (! array_key_exists($option->name, $chosen)) {
                    $validator->errors()->add('option_values', "Choose a value for {$option->display_name}.");

                    return;
                }
            }

            $signature = VariantGenerator::signature($chosen);

            $duplicate = $product->variants()
                ->when($this->variant(), fn ($query, ProductVariant $variant) => $query->whereKeyNot($variant->id))
                ->get(['id', 'option_values'])
                ->contains(fn (ProductVariant $other) => VariantGenerator::signature((array) $other->option_values) === $signature);

            if ($duplicate) {
                $validator->errors()->add('option_values', 'Another variant already has this combination.');
            }
        });
    }
}
