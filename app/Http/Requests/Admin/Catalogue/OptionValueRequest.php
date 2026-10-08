<?php

namespace App\Http\Requests\Admin\Catalogue;

use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OptionValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('value');

        if (is_string($value)) {
            $this->merge(['value' => trim($value)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $value = $this->route('value');
        $option = $this->route('option');

        $optionId = $value instanceof ProductOptionValue ? $value->product_option_id : ($option instanceof ProductOption ? $option->id : null);

        return [
            'value' => [
                'required', 'string', 'max:120',
                Rule::unique('product_option_values', 'value')
                    ->where('product_option_id', $optionId)
                    ->ignore($value instanceof ProductOptionValue ? $value->id : null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.unique' => 'This option already has that value.',
        ];
    }
}
