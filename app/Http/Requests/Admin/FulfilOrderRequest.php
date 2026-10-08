<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FulfilOrderRequest extends FormRequest
{
    /**
     * Editing orders is enforced by the route.
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
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            // Customers are sent to this link, so only web addresses are allowed.
            'tracking_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'notify_customer' => ['sometimes', 'boolean'],
        ];
    }
}
