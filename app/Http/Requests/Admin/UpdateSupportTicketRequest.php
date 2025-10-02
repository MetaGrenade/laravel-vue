<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupportTicketRequest extends FormRequest
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
        return $this->user()->can('support.acp.edit');
    }

    public function rules()
    {
        return [
            'subject'     => 'sometimes|required|string|max:255',
            'body'        => 'sometimes|required|string',
            'status'      => 'sometimes|required|in:open,pending,closed',
            'priority'    => 'in:low,medium,high',
            'assigned_to' => 'nullable|exists:users,id',
            'user_id'     => 'sometimes|nullable|exists:users,id',
            'support_ticket_category_id' => 'sometimes|nullable|exists:support_ticket_categories,id',
            // etc...
        ];
    }
}
