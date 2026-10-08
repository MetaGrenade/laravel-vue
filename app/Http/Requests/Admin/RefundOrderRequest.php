<?php

namespace App\Http\Requests\Admin;

use App\Models\Refund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundOrderRequest extends FormRequest
{
    /**
     * The refund permission is enforced by the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // At most two decimals, for example 12 or 12.50.
            'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'reason' => ['nullable', Rule::in(Refund::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
            'restock' => ['sometimes', 'boolean'],
            'notify_customer' => ['sometimes', 'boolean'],
            // The money went back another way (cash, a bank transfer): record it, ask no one.
            'manual' => ['sometimes', 'boolean'],
            // Made when the form opens, so a double click is one refund.
            'token' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter an amount such as 12 or 12.50.',
        ];
    }
}
