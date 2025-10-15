<?php

namespace App\Http\Requests\Admin;

use App\Models\DataErasureRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDataErasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('trust_safety.acp.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(DataErasureRequest::STATUSES)],
            'processed_at' => ['nullable', 'date'],
        ];
    }
}
