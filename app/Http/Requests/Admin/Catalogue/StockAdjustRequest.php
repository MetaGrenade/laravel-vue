<?php

namespace App\Http\Requests\Admin\Catalogue;

use App\Support\Commerce\Catalogue\StockAdjuster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Two things can change on a tracked item: the count (set to a figure, or add or remove some)
     * and whether it may be sold when it runs out. Either can be sent on its own.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limit = StockAdjuster::LIMIT;

        return [
            'mode' => ['required_with:quantity', 'nullable', Rule::in(['set', 'add'])],
            'quantity' => ['required_with:mode', 'nullable', 'integer', "between:-{$limit},{$limit}"],
            'note' => ['nullable', 'string', 'max:255'],
            'allow_backorder' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.integer' => 'Enter a whole number.',
        ];
    }
}
