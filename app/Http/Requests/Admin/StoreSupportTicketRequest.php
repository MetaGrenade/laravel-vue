<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupportTicketRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
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

    public function authorize()
    {
        return $this->user()->can('support.acp.create');
    }

    public function rules()
    {
        return [
            'subject'  => 'required|string|max:255',
            'body'     => 'required|string',
            'priority' => 'in:low,medium,high',
            'support_ticket_category_id' => 'nullable|exists:support_ticket_categories,id',
            'user_id'  => 'nullable|exists:users,id',
            // add any other fields
        ];
    }
}
