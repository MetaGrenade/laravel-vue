<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()) {
            $this->merge([
                'user_id' => $this->user()->id,
            ]);
        }

        if ($this->has('support_ticket_category_id')) {
            $category = $this->input('support_ticket_category_id');

            if ($category === '' || $category === 'null') {
                $category = null;
            }

            $this->merge([
                'support_ticket_category_id' => $category !== null ? (int) $category : null,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'priority' => ['nullable', 'in:low,medium,high'],
            'support_ticket_category_id' => ['nullable', 'exists:support_ticket_categories,id'],
            'user_id' => ['required', 'exists:users,id'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'max:10240',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,text/csv,application/zip,application/json,application/x-ndjson,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ];
    }
}
